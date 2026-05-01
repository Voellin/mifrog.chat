<?php

namespace App\Services;

use App\Models\Run;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

class CalendarTaskService extends AbstractFeishuTaskService
{
    private const REQUIRED_SCOPE_CREATE = 'calendar:calendar.event:create';
    private const REQUIRED_SCOPE_UPDATE = 'calendar:calendar.event:update';
    private const DEFAULT_TIMEZONE = 'Asia/Shanghai';

    public function __construct(
        FeishuService $feishuService,
        private readonly FeishuTokenService $feishuTokenService,
        FeishuCliClient $feishuCliClient,
    ) {
        parent::__construct($feishuService, $feishuCliClient);
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    public function createEvent(Run $run, array $params): array
    {
        if (($params['_extraction_failed'] ?? false) === true) {
            return [
                'status' => 'clarify',
                'message' => 'Please tell me the schedule title and time, for example "Tomorrow 9am kickoff meeting".',
            ];
        }

        if (($params['needs_clarification'] ?? false) === true) {
            $message = trim((string) ($params['clarification_message'] ?? ''));

            return [
                'status' => 'clarify',
                'message' => $message !== '' ? $message : 'Please add the meeting time so I can create the calendar event.',
            ];
        }

        [$accessToken, $identity, $error] = $this->feishuTokenService->resolveUserToken($run, self::REQUIRED_SCOPE_CREATE, 'calendar create');
        if ($error !== null) {
            return $error;
        }
        $userKey = trim((string) ($identity?->provider_user_id ?: $this->resolveRunOpenId($run)));

        if (! $this->feishuCliClient->isEnabled() || ! $this->feishuCliClient->isAvailable()) {
            return [
                'status' => 'failed',
                'message' => 'Feishu CLI is not available, so I cannot create a calendar event right now.',
            ];
        }

        $summary = trim((string) ($params['summary'] ?? 'Scheduled event'));
        $location = trim((string) ($params['location'] ?? ''));
        $description = trim((string) ($params['description'] ?? ''));

        $startTime = $this->parseIsoTime((string) ($params['start_time'] ?? ''));
        $endTime = $this->parseIsoTime((string) ($params['end_time'] ?? ''));

        if ($startTime === null) {
            Log::warning('[CalendarTask] Invalid start_time from planner', [
                'run_id' => $run->id,
                'params' => $params,
            ]);

            return [
                'status' => 'clarify',
                'message' => 'I could not understand the meeting time. Please restate it with a concrete date and time.',
            ];
        }

        if ($endTime === null || $endTime->lessThanOrEqualTo($startTime)) {
            $endTime = $startTime->addHour();
        }

        $feishuConfig = $this->feishuService->readConfig();
        $descriptionParts = [];
        if ($description !== '') {
            $descriptionParts[] = $description;
        }
        if ($location !== '') {
            $descriptionParts[] = 'Location: ' . $location;
        }
        if ($descriptionParts === []) {
            $descriptionParts[] = 'Created by MiFrog.';
        }

        $command = [
            'calendar', '+create',
            '--summary', $summary,
            '--start', $startTime->toIso8601String(),
            '--end', $endTime->toIso8601String(),
            '--description', implode("\n", $descriptionParts),
        ];

        Log::debug('[CalendarTask] Creating event via CLI', [
            'run_id' => $run->id,
            'command' => $command,
        ]);

        try {
            $create = $this->feishuCliClient->runSkillCommand($feishuConfig, $accessToken, $command, 'user', $userKey);
        } catch (Throwable $e) {
            return $this->cliFailureResult(
                $e,
                'Feishu authorization is required before I can create this calendar event.',
                'Creating the calendar event failed'
            );
        }

        $cliCode = (int) ($create['code'] ?? 0);
        if ($cliCode !== 0) {
            if ($this->looksLikeAuthorizationPayload($create)) {
                return $this->blockedFromCliPayload($create, 'Feishu authorization is required before I can create this calendar event.');
            }

            return [
                'status' => 'failed',
                'message' => 'Creating the calendar event failed: ' . trim((string) ($create['msg'] ?? 'calendar_event_create_failed')),
                'error' => $create,
            ];
        }

        $data = (array) ($create['data'] ?? $create);
        $eventId = trim((string) ($data['event']['event_id'] ?? ($data['event_id'] ?? '')));
        $calendarId = trim((string) ($data['event']['organizer_calendar_id'] ?? ($data['organizer_calendar_id'] ?? '')));
        $eventUrl = $this->buildEventUrl($calendarId, $eventId);

        $lines = [];
        $lines[] = 'I created the calendar event "' . $summary . '".';
        $lines[] = 'Start: ' . $startTime->format('Y-m-d H:i');
        $lines[] = 'End: ' . $endTime->format('Y-m-d H:i');
        if ($location !== '') {
            $lines[] = 'Location: ' . $location;
        }
        if ($eventUrl !== '') {
            $lines[] = 'Link: ' . $eventUrl;
        }
        $lines[] = 'If you want, I can also add attendees or adjust the time.';

        return [
            'status' => 'created',
            'message' => implode("\n", $lines),
            'event' => $create,
            'event_id' => $eventId,
            'calendar_id' => $calendarId,
            'event_url' => $eventUrl,
            'summary' => $summary,
        ];
    }

    /**
     * @param  array<string,mixed>  $params
     * @param  array<int,array<string,mixed>>  $rawMessages
     * @return array<string,mixed>
     */
    public function addAttendeesToEvent(Run $run, array $params, array $rawMessages = []): array
    {
        if (($params['_extraction_failed'] ?? false) === true) {
            return [
                'status' => 'clarify',
                'message' => 'Please tell me which attendee to add and which calendar event it belongs to.',
            ];
        }

        if (($params['needs_clarification'] ?? false) === true) {
            $message = trim((string) ($params['clarification_message'] ?? ''));

            return [
                'status' => 'clarify',
                'message' => $message !== '' ? $message : 'Please tell me which attendee to add and which calendar event it belongs to.',
            ];
        }

        [$accessToken, $identity, $error] = $this->feishuTokenService->resolveUserToken($run, self::REQUIRED_SCOPE_UPDATE, 'calendar attendee update');
        if ($error !== null) {
            return $error;
        }
        $userKey = trim((string) ($identity?->provider_user_id ?: $this->resolveRunOpenId($run)));

        if (! $this->feishuCliClient->isEnabled() || ! $this->feishuCliClient->isAvailable()) {
            return [
                'status' => 'failed',
                'message' => 'Feishu CLI is not available, so I cannot update calendar attendees right now.',
            ];
        }

        $eventContext = $this->resolveTargetEventContext($params, $rawMessages);
        if (($eventContext['event_id'] ?? '') === '') {
            return [
                'status' => 'clarify',
                'message' => 'I cannot tell which calendar event you mean yet. Please mention the event title or send the event link.',
            ];
        }

        if (($eventContext['calendar_id'] ?? '') === '') {
            return [
                'status' => 'clarify',
                'message' => 'I found the event, but I still need the calendar link or calendar id to update attendees.',
            ];
        }

        $feishuConfig = $this->feishuService->readConfig();
        $attendeeResolution = $this->resolveAttendees($feishuConfig, $accessToken, $userKey, $params);
        if (($attendeeResolution['status'] ?? '') !== 'resolved') {
            return $attendeeResolution;
        }

        $paramsPayload = $this->encodeCliJson([
            'calendar_id' => (string) $eventContext['calendar_id'],
            'event_id' => (string) $eventContext['event_id'],
            'user_id_type' => (string) $attendeeResolution['user_id_type'],
        ]);
        $dataPayload = $this->encodeCliJson([
            'attendees' => array_values((array) ($attendeeResolution['attendees'] ?? [])),
            'need_notification' => true,
        ]);

        $command = [
            'calendar', 'event.attendees', 'create',
            '--format', 'json',
            '--params', $paramsPayload,
            '--data', $dataPayload,
        ];

        Log::debug('[CalendarTask] Adding attendees via CLI', [
            'run_id' => $run->id,
            'event_id' => $eventContext['event_id'],
            'calendar_id' => $eventContext['calendar_id'],
            'attendee_labels' => $attendeeResolution['labels'] ?? [],
        ]);

        try {
            $result = $this->feishuCliClient->runSkillCommand($feishuConfig, $accessToken, $command, 'user', $userKey);
        } catch (Throwable $e) {
            return $this->cliFailureResult(
                $e,
                'Feishu authorization is required before I can add attendees to this calendar event.',
                'Adding attendees failed'
            );
        }

        $code = (int) ($result['code'] ?? 0);
        if ($code !== 0) {
            if ($this->looksLikeAuthorizationPayload($result)) {
                return $this->blockedFromCliPayload($result, 'Feishu authorization is required before I can add attendees to this calendar event.');
            }

            return [
                'status' => 'failed',
                'message' => 'Adding attendees failed: ' . trim((string) ($result['msg'] ?? 'calendar_attendee_update_failed')),
                'error' => $result,
            ];
        }

        $labels = array_values((array) ($attendeeResolution['labels'] ?? []));
        $eventUrl = $this->buildEventUrl((string) $eventContext['calendar_id'], (string) $eventContext['event_id']);
        $summary = trim((string) ($eventContext['summary'] ?? ''));

        $lines = [];
        $lines[] = 'I added ' . implode(', ', $labels) . ' to the calendar event' . ($summary !== '' ? ' "' . $summary . '"' : '') . '.';
        if ($eventUrl !== '') {
            $lines[] = 'Link: ' . $eventUrl;
        }

        return [
            'status' => 'success',
            'message' => implode("\n", $lines),
            'event_id' => (string) $eventContext['event_id'],
            'calendar_id' => (string) $eventContext['calendar_id'],
            'event_url' => $eventUrl,
            'summary' => $summary,
            'raw_data' => (array) ($result['data'] ?? $result),
        ];
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    public function readAgenda(Run $run, array $params): array
    {
        if (($params['_extraction_failed'] ?? false) === true) {
            return [
                'status' => 'clarify',
                'message' => 'Please tell me the time range you want to check, for example "What meetings do I have tomorrow?"',
            ];
        }

        if (($params['needs_clarification'] ?? false) === true) {
            $message = trim((string) ($params['clarification_message'] ?? ''));

            return [
                'status' => 'clarify',
                'message' => $message !== '' ? $message : 'Please tell me the time range you want to check, for example "What meetings do I have tomorrow?"',
            ];
        }

        [$accessToken, $identity, $error] = $this->feishuTokenService->resolveUserToken($run, '', 'calendar agenda');
        if ($error !== null) {
            return $error;
        }
        $userKey = trim((string) ($identity?->provider_user_id ?: $this->resolveRunOpenId($run)));

        if (! $this->feishuCliClient->isEnabled() || ! $this->feishuCliClient->isAvailable()) {
            return [
                'status' => 'failed',
                'message' => 'Feishu CLI is not available, so I cannot read the calendar agenda right now.',
            ];
        }

        $startTime = $this->parseIsoTime((string) ($params['start_time'] ?? '')) ?? CarbonImmutable::now(self::DEFAULT_TIMEZONE)->startOfDay();
        $endTime = $this->parseIsoTime((string) ($params['end_time'] ?? ''));
        if ($endTime === null || $endTime->lessThanOrEqualTo($startTime)) {
            $endTime = $startTime->addDay();
        }

        $limit = is_numeric($params['limit'] ?? null) ? (int) $params['limit'] : 10;
        $limit = min(20, max(1, $limit));
        $keyword = $this->normalizeMatchText((string) ($params['keyword'] ?? ''));

        $command = [
            'calendar', '+agenda',
            '--start', $startTime->toIso8601String(),
            '--end', $endTime->toIso8601String(),
            '--format', 'json',
        ];

        try {
            $result = $this->feishuCliClient->runSkillCommand(
                $this->feishuService->readConfig(),
                $accessToken,
                $command,
                'user',
                $userKey
            );
        } catch (Throwable $e) {
            return $this->cliFailureResult(
                $e,
                'Feishu authorization is required before I can read your calendar agenda.',
                'Reading the calendar agenda failed'
            );
        }

        $code = (int) ($result['code'] ?? 0);
        if ($code !== 0) {
            if ($this->looksLikeAuthorizationPayload($result)) {
                return $this->blockedFromCliPayload($result, 'Feishu authorization is required before I can read your calendar agenda.');
            }

            return [
                'status' => 'failed',
                'message' => 'Reading the calendar agenda failed: ' . trim((string) ($result['msg'] ?? 'calendar_agenda_read_failed')),
                'error' => $result,
            ];
        }

        $events = [];
        foreach ((array) ($result['data'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $record = $this->normalizeAgendaItem($item);
            if ($record === null) {
                continue;
            }

            if ($keyword !== '' && ! $this->agendaItemMatchesKeyword($record, $keyword)) {
                continue;
            }

            $events[] = $record;
            if (count($events) >= $limit) {
                break;
            }
        }

        if ($events === []) {
            return [
                'status' => 'read',
                'message' => 'I did not find any calendar events in that time window.',
                'events' => [],
                'raw_data' => [
                    'events' => [],
                    'start_time' => $startTime->toIso8601String(),
                    'end_time' => $endTime->toIso8601String(),
                    'keyword' => $keyword,
                ],
            ];
        }

        $lines = [];
        $lines[] = sprintf('I found %d calendar event%s.', count($events), count($events) === 1 ? '' : 's');
        foreach ($events as $index => $event) {
            $parts = [
                trim((string) ($event['summary'] ?? 'Untitled event')),
                trim((string) ($event['start_time'] ?? '')),
            ];

            $endLabel = trim((string) ($event['end_time'] ?? ''));
            if ($endLabel !== '') {
                $parts[] = 'to ' . $endLabel;
            }

            $location = trim((string) ($event['location'] ?? ''));
            if ($location !== '') {
                $parts[] = 'location=' . $location;
            }

            $lines[] = ($index + 1) . '. ' . implode(' | ', array_filter($parts, static fn (string $item): bool => trim($item) !== ''));
        }

        return [
            'status' => 'read',
            'message' => implode("\n", $lines),
            'events' => $events,
            'raw_data' => [
                'events' => $events,
                'start_time' => $startTime->toIso8601String(),
                'end_time' => $endTime->toIso8601String(),
                'keyword' => $keyword,
            ],
        ];
    }

    private function parseIsoTime(string $timeStr): ?CarbonImmutable
    {
        $timeStr = trim($timeStr);
        if ($timeStr === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($timeStr, self::DEFAULT_TIMEZONE);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,string>|null
     */
    private function normalizeAgendaItem(array $item): ?array
    {
        $summary = trim((string) ($item['summary'] ?? $item['title'] ?? ''));
        $start = $this->normalizeAgendaTime($item['start_time'] ?? $item['start'] ?? '');
        $end = $this->normalizeAgendaTime($item['end_time'] ?? $item['end'] ?? '');

        if ($summary === '' && $start === '' && $end === '') {
            return null;
        }

        $organizerRaw = $item['event_organizer'] ?? $item['organizer'] ?? '';
        $organizer = is_array($organizerRaw)
            ? trim((string) ($organizerRaw['display_name'] ?? ''))
            : trim((string) $organizerRaw);

        $calendarId = trim((string) ($item['calendar_id'] ?? ''));
        $eventId = trim((string) ($item['event_id'] ?? ($item['key'] ?? '')));

        return [
            'summary' => $summary !== '' ? $summary : 'Untitled event',
            'start_time' => $start,
            'end_time' => $end,
            'location' => trim((string) ($item['location'] ?? '')),
            'organizer' => $organizer,
            'status' => trim((string) ($item['status'] ?? '')),
            'calendar_id' => $calendarId,
            'event_id' => $eventId,
            'event_url' => $this->buildEventUrl($calendarId, $eventId),
        ];
    }

    private function normalizeAgendaTime(mixed $value): string
    {
        if (is_array($value)) {
            $value = (string) ($value['datetime'] ?? ($value['date'] ?? ''));
        }

        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        try {
            return CarbonImmutable::parse($value, self::DEFAULT_TIMEZONE)->format('Y-m-d H:i');
        } catch (Throwable) {
            return $value;
        }
    }

    /**
     * @param  array<string,string>  $event
     */
    private function agendaItemMatchesKeyword(array $event, string $keyword): bool
    {
        foreach (['summary', 'location', 'organizer', 'status'] as $field) {
            $value = $this->normalizeMatchText((string) ($event[$field] ?? ''));
            if ($value !== '' && str_contains($value, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $params
     * @param  array<int,array<string,mixed>>  $rawMessages
     * @return array<string,string>
     */
    private function resolveTargetEventContext(array $params, array $rawMessages): array
    {
        $calendarId = trim((string) ($params['calendar_id'] ?? ''));
        $eventId = trim((string) ($params['event_id'] ?? ''));
        $summary = trim((string) ($params['event_summary'] ?? ''));
        $eventUrl = trim((string) ($params['event_url'] ?? ''));

        if ($eventId === '') {
            $eventIds = $this->normalizeStringList($params['calendar_event_ids'] ?? []);
            $eventId = $eventIds[0] ?? '';
        }

        if ($eventUrl !== '') {
            $parsed = $this->parseEventUrl($eventUrl);
            $calendarId = $calendarId !== '' ? $calendarId : ($parsed['calendar_id'] ?? '');
            $eventId = $eventId !== '' ? $eventId : ($parsed['event_id'] ?? '');
        }

        if ($calendarId !== '' && $eventId !== '') {
            return [
                'calendar_id' => $calendarId,
                'event_id' => $eventId,
                'summary' => $summary,
            ];
        }

        for ($i = count($rawMessages) - 1; $i >= 0; $i--) {
            $content = trim((string) ($rawMessages[$i]['content'] ?? ''));
            if ($content === '') {
                continue;
            }

            $parsed = $this->parseEventUrl($content);
            if (($parsed['calendar_id'] ?? '') !== '' || ($parsed['event_id'] ?? '') !== '') {
                return [
                    'calendar_id' => $calendarId !== '' ? $calendarId : (string) ($parsed['calendar_id'] ?? ''),
                    'event_id' => $eventId !== '' ? $eventId : (string) ($parsed['event_id'] ?? ''),
                    'summary' => $summary !== '' ? $summary : $this->extractSummaryFromMessage($content),
                ];
            }

            if ($summary === '') {
                $summary = $this->extractSummaryFromMessage($content);
            }
        }

        return [
            'calendar_id' => $calendarId,
            'event_id' => $eventId,
            'summary' => $summary,
        ];
    }

    /**
     * @param  array<string,mixed>  $feishuConfig
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    private function resolveAttendees(array $feishuConfig, string $accessToken, string $userKey, array $params): array
    {
        $explicitIds = $this->normalizeStringList($params['attendee_user_ids'] ?? []);
        $nameHints = $this->normalizeStringList($params['attendee_names'] ?? []);

        if ($explicitIds === [] && $nameHints === []) {
            return [
                'status' => 'clarify',
                'message' => 'Please tell me which attendee to add.',
            ];
        }

        if ($explicitIds !== []) {
            $userIdType = strtolower(trim((string) ($params['user_id_type'] ?? 'user_id')));
            if (! in_array($userIdType, ['user_id', 'open_id', 'union_id'], true)) {
                $userIdType = 'user_id';
            }

            return [
                'status' => 'resolved',
                'user_id_type' => $userIdType,
                'attendees' => array_map(
                    static fn (string $userId) => ['type' => 'user', 'user_id' => $userId],
                    $explicitIds
                ),
                'labels' => $explicitIds,
            ];
        }

        $resolvedPeople = [];
        foreach ($nameHints as $name) {
            $resolved = $this->resolveSingleAttendeeByName($feishuConfig, $accessToken, $userKey, $name);
            if (($resolved['status'] ?? '') !== 'resolved') {
                return $resolved;
            }
            $resolvedPeople[] = $resolved;
        }

        $chosenType = $this->chooseCommonUserIdType($resolvedPeople);
        if ($chosenType === null) {
            return [
                'status' => 'clarify',
                'message' => 'I found the attendee records, but their available identifiers do not line up cleanly yet. Please send the person\'s contact card or an explicit Feishu user id.',
            ];
        }

        $attendees = [];
        $labels = [];
        foreach ($resolvedPeople as $person) {
            $ids = (array) ($person['ids'] ?? []);
            $attendees[] = [
                'type' => 'user',
                'user_id' => (string) ($ids[$chosenType] ?? ''),
            ];
            $labels[] = (string) ($person['label'] ?? '');
        }

        return [
            'status' => 'resolved',
            'user_id_type' => $chosenType,
            'attendees' => $attendees,
            'labels' => array_values(array_filter($labels, static fn (string $item) => trim($item) !== '')),
        ];
    }

    /**
     * @param  array<string,mixed>  $feishuConfig
     * @return array<string,mixed>
     */
    private function resolveSingleAttendeeByName(array $feishuConfig, string $accessToken, string $userKey, string $name): array
    {
        $command = [
            'contact', '+search-user',
            '--query', $name,
            '--format', 'json',
        ];

        try {
            $result = $this->feishuCliClient->runSkillCommand($feishuConfig, $accessToken, $command, 'user', $userKey);
        } catch (Throwable $e) {
            return $this->cliFailureResult(
                $e,
                'Feishu authorization is required before I can look up that attendee.',
                'Looking up the attendee failed'
            );
        }

        $code = (int) ($result['code'] ?? 0);
        if ($code !== 0) {
            if ($this->looksLikeAuthorizationPayload($result)) {
                return $this->blockedFromCliPayload($result, 'Feishu authorization is required before I can look up that attendee.');
            }

            return [
                'status' => 'failed',
                'message' => 'Looking up the attendee failed: ' . trim((string) ($result['msg'] ?? 'contact_lookup_failed')),
                'error' => $result,
            ];
        }

        $data = (array) ($result['data'] ?? $result);
        $users = array_values(array_filter((array) ($data['users'] ?? []), 'is_array'));
        if ($users === []) {
            return [
                'status' => 'clarify',
                'message' => 'I could not find a Feishu contact matching "' . $name . '". Please send a clearer name, email, or contact card.',
            ];
        }

        $matches = $this->rankContactCandidates($users, $name);
        if (count($matches) > 1) {
            $options = array_map(
                fn (array $user) => $this->formatContactCandidate($user),
                array_slice($matches, 0, 3)
            );

            return [
                'status' => 'clarify',
                'message' => 'I found multiple contacts for "' . $name . '". Please tell me which one you mean: ' . implode(' / ', $options),
            ];
        }

        $chosen = (array) ($matches[0] ?? []);
        $ids = [
            'user_id' => trim((string) ($chosen['user_id'] ?? '')),
            'open_id' => trim((string) ($chosen['open_id'] ?? '')),
            'union_id' => trim((string) ($chosen['union_id'] ?? '')),
        ];

        if ($ids['user_id'] === '' && $ids['open_id'] === '' && $ids['union_id'] === '') {
            return [
                'status' => 'failed',
                'message' => 'I found the contact "' . $name . '", but Feishu did not return a usable user id.',
                'raw_data' => $chosen,
            ];
        }

        return [
            'status' => 'resolved',
            'ids' => $ids,
            'label' => $this->formatContactCandidate($chosen),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $users
     * @return array<int,array<string,mixed>>
     */
    private function rankContactCandidates(array $users, string $name): array
    {
        $needle = $this->normalizeMatchText($name);
        $exact = [];
        $fuzzy = [];

        foreach ($users as $user) {
            $displayName = $this->normalizeMatchText((string) ($user['name'] ?? ($user['en_name'] ?? '')));
            $email = $this->normalizeMatchText((string) ($user['email'] ?? ($user['enterprise_email'] ?? '')));
            $mobile = $this->normalizeMatchText((string) ($user['mobile'] ?? ''));

            if ($displayName !== '' && $displayName === $needle) {
                $exact[] = $user;
                continue;
            }

            if ($email !== '' && str_contains($email, $needle)) {
                $exact[] = $user;
                continue;
            }

            if ($mobile !== '' && str_contains($mobile, $needle)) {
                $exact[] = $user;
                continue;
            }

            $fuzzy[] = $user;
        }

        return $exact !== [] ? $exact : $fuzzy;
    }

    /**
     * @param  array<int,array<string,mixed>>  $resolvedPeople
     */
    private function chooseCommonUserIdType(array $resolvedPeople): ?string
    {
        foreach (['open_id', 'user_id', 'union_id'] as $candidateType) {
            $allHaveType = true;
            foreach ($resolvedPeople as $person) {
                $ids = (array) ($person['ids'] ?? []);
                if (trim((string) ($ids[$candidateType] ?? '')) === '') {
                    $allHaveType = false;
                    break;
                }
            }

            if ($allHaveType) {
                return $candidateType;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function cliFailureResult(Throwable $e, string $authMessage, string $failurePrefix): array
    {
        $error = trim($e->getMessage());
        $scopes = $this->extractScopesFromText($error);
        if ($this->looksLikeAuthorizationText($error) || $scopes !== []) {
            $missing = ['feishu.oauth.user_token'];
            foreach ($scopes as $scope) {
                $missing[] = 'feishu.scope.' . $scope;
            }

            return [
                'status' => 'blocked',
                'message' => $authMessage,
                'missing' => array_values(array_unique($missing)),
                'error' => $error,
            ];
        }

        return [
            'status' => 'failed',
            'message' => $failurePrefix . ': ' . $this->truncate($error, 240),
            'error' => $error,
        ];
    }

    /**
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private function blockedFromCliPayload(array $result, string $message): array
    {
        $errorText = trim((string) ($result['msg'] ?? ''));
        $missing = ['feishu.oauth.user_token'];
        foreach ($this->extractScopesFromText($errorText) as $scope) {
            $missing[] = 'feishu.scope.' . $scope;
        }

        return [
            'status' => 'blocked',
            'message' => $message,
            'missing' => array_values(array_unique($missing)),
            'error' => $result,
        ];
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function looksLikeAuthorizationPayload(array $result): bool
    {
        $errorType = strtolower(trim((string) ($result['error_type'] ?? '')));
        if (in_array($errorType, ['auth', 'token', 'permission', 'config'], true)) {
            return true;
        }

        $message = trim((string) ($result['msg'] ?? ''));

        return $this->looksLikeAuthorizationText($message);
    }

    private function looksLikeAuthorizationText(string $text): bool
    {
        $text = strtolower(trim($text));
        if ($text === '') {
            return false;
        }

        return str_contains($text, '[auth]')
            || str_contains($text, 'required scope:')
            || str_contains($text, 'not logged in')
            || str_contains($text, 'login required')
            || str_contains($text, 'authorization required')
            || str_contains($text, 'authorization is required')
            || str_contains($text, 'unauthorized')
            || str_contains($text, 'invalid access token')
            || str_contains($text, 'access token expired')
            || str_contains($text, '授权')
            || str_contains($text, '未登录')
            || str_contains($text, '缺少scope');
    }


    /**
     * @param  mixed  $value
     * @return array<int,string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (is_string($value)) {
            $items = preg_split('/[\s,]+/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        } elseif (is_array($value)) {
            $items = $value;
        } else {
            $items = [];
        }

        $set = [];
        foreach ($items as $item) {
            $normalized = trim((string) $item);
            if ($normalized !== '') {
                $set[$normalized] = true;
            }
        }

        return array_keys($set);
    }

    /**
     * @return array<string,string>
     */
    private function parseEventUrl(string $text): array
    {
        if (preg_match_all('#https?://[^\s<>"\'，。！？、）】》]+#u', $text, $matches) !== 1 || empty($matches[0])) {
            return [];
        }

        $urls = array_reverse($matches[0]);
        foreach ($urls as $url) {
            if (! str_contains($url, '/calendar/event/detail')) {
                continue;
            }

            $parts = parse_url(html_entity_decode($url, ENT_QUOTES | ENT_HTML5));
            parse_str((string) ($parts['query'] ?? ''), $query);

            $calendarId = trim((string) ($query['calendarId'] ?? ''));
            $eventId = trim((string) ($query['key'] ?? ''));
            if ($calendarId !== '' || $eventId !== '') {
                return [
                    'calendar_id' => $calendarId,
                    'event_id' => $eventId,
                ];
            }
        }

        return [];
    }

    private function buildEventUrl(string $calendarId, string $eventId): string
    {
        $calendarId = trim($calendarId);
        $eventId = trim($eventId);
        if ($eventId === '') {
            return '';
        }

        return 'https://applink.feishu.cn/client/calendar/event/detail?calendarId=' . urlencode($calendarId !== '' ? $calendarId : 'primary') . '&key=' . urlencode($eventId);
    }

    private function extractSummaryFromMessage(string $content): string
    {
        if (preg_match('/created the calendar event\s+"([^"]+)"/iu', $content, $matches) === 1) {
            return trim((string) ($matches[1] ?? ''));
        }

        if (preg_match('/已为你创建日程[:：]\s*(.+)$/u', $content, $matches) === 1) {
            return trim((string) ($matches[1] ?? ''));
        }

        return '';
    }

    /**
     * @param  array<string,mixed>  $user
     */
    private function formatContactCandidate(array $user): string
    {
        $name = trim((string) ($user['name'] ?? ($user['en_name'] ?? '')));
        $email = trim((string) ($user['email'] ?? ($user['enterprise_email'] ?? '')));
        $mobile = trim((string) ($user['mobile'] ?? ''));

        $parts = [];
        if ($name !== '') {
            $parts[] = $name;
        }
        if ($email !== '') {
            $parts[] = 'email=' . $email;
        }
        if ($mobile !== '') {
            $parts[] = 'mobile=' . $mobile;
        }

        return $parts !== [] ? implode(', ', $parts) : 'unknown contact';
    }

    private function normalizeMatchText(string $text): string
    {
        return function_exists('mb_strtolower')
            ? mb_strtolower(trim($text), 'UTF-8')
            : strtolower(trim($text));
    }


    /**
     * @param  array<string,mixed>  $data
     */
    private function encodeCliJson(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($json)) {
            throw new \RuntimeException('Failed to encode CLI JSON payload.');
        }

        return $json;
    }

}
