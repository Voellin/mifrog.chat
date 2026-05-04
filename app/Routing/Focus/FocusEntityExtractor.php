<?php

namespace App\Routing\Focus;

use App\Models\Run;
use App\Routing\Contracts\FocusSnapshot;

final class FocusEntityExtractor
{
    public function extractFromRun(Run $run): ?FocusSnapshot
    {
        $meta = is_array($run->intent_meta) ? $run->intent_meta : [];
        $focusOutput = $meta['focus_output'] ?? null;
        if (! is_array($focusOutput)) {
            return null;
        }

        return $this->snapshotFromStored($focusOutput);
    }

    /**
     * @param  array<string,mixed>  $focusOutput
     */
    public function snapshotFromStored(array $focusOutput): ?FocusSnapshot
    {
        $objectType = strtolower(trim((string) ($focusOutput['object_type'] ?? '')));
        if ($objectType === '') {
            return null;
        }

        return new FocusSnapshot(
            $objectType,
            trim((string) ($focusOutput['object_id'] ?? '')),
            trim((string) ($focusOutput['summary'] ?? '')),
            max(0.0, min(1.0, (float) ($focusOutput['confidence'] ?? 0.0))),
            is_array($focusOutput['attributes'] ?? null) ? $focusOutput['attributes'] : []
        );
    }

    /**
     * @param  array<string,mixed>  $platformResult
     * @return array<string,mixed>|null
     */
    public function extractFromPlatformResult(array $platformResult): ?array
    {
        $raw = is_array($platformResult['raw'] ?? null) ? $platformResult['raw'] : [];
        $workAction = strtolower(trim((string) ($platformResult['work_action'] ?? '')));

        return $this->extractCalendarFocus($raw, $workAction)
            ?? $this->extractDocumentFocus($raw, $workAction)
            ?? $this->extractSheetFocus($raw, $workAction)
            ?? $this->extractBaseFocus($raw, $workAction)
            ?? $this->extractMeetingFocus($raw, $workAction);
    }

    /**
     * @param  array<string,mixed>  $raw
     * @return array<string,mixed>|null
     */
    private function extractCalendarFocus(array $raw, string $workAction): ?array
    {
        $eventId = $this->firstNonEmptyString($raw['event_id'] ?? null);
        $calendarId = $this->firstNonEmptyString($raw['calendar_id'] ?? null);
        $eventUrl = $this->firstNonEmptyString($raw['event_url'] ?? null);
        $summary = $this->firstNonEmptyString($raw['summary'] ?? ($raw['event_summary'] ?? null));
        if ($eventId === '' && $calendarId === '' && $eventUrl === '') {
            return null;
        }

        return [
            'object_type' => 'calendar_event',
            'object_id' => $eventId !== '' ? $eventId : $eventUrl,
            'summary' => $summary !== '' ? $summary : 'Recent calendar event',
            'confidence' => 0.98,
            'attributes' => array_filter([
                'calendar_id' => $calendarId,
                'event_id' => $eventId,
                'event_url' => $eventUrl,
                'summary' => $summary,
                'source_work_action' => $workAction,
            ], static fn ($value) => ! ($value === '' || $value === null)),
        ];
    }

    /**
     * @param  array<string,mixed>  $raw
     * @return array<string,mixed>|null
     */
    private function extractDocumentFocus(array $raw, string $workAction): ?array
    {
        $documentId = $this->firstNonEmptyString($raw['document_id'] ?? ($raw['doc_token'] ?? null));
        $documentUrl = $this->firstNonEmptyString($raw['document_url'] ?? ($raw['url'] ?? null));
        $title = $this->firstNonEmptyString($raw['title'] ?? null);
        if ($documentId === '' && $documentUrl === '') {
            return null;
        }

        return [
            'object_type' => 'document',
            'object_id' => $documentId !== '' ? $documentId : $documentUrl,
            'summary' => $title !== '' ? $title : 'Recent document',
            'confidence' => 0.96,
            'attributes' => array_filter([
                'document_id' => $documentId,
                'document_url' => $documentUrl,
                'title' => $title,
                'source_work_action' => $workAction,
            ], static fn ($value) => ! ($value === '' || $value === null)),
        ];
    }

    /**
     * @param  array<string,mixed>  $raw
     * @return array<string,mixed>|null
     */
    private function extractSheetFocus(array $raw, string $workAction): ?array
    {
        $spreadsheetToken = $this->firstNonEmptyString($raw['spreadsheet_token'] ?? ($raw['token'] ?? null));
        $spreadsheetUrl = $this->firstNonEmptyString($raw['url'] ?? ($raw['spreadsheet_url'] ?? null));
        $title = $this->firstNonEmptyString($raw['title'] ?? null);
        if ($spreadsheetToken === '' && $spreadsheetUrl === '') {
            return null;
        }

        return [
            'object_type' => 'sheet',
            'object_id' => $spreadsheetToken !== '' ? $spreadsheetToken : $spreadsheetUrl,
            'summary' => $title !== '' ? $title : 'Recent spreadsheet',
            'confidence' => 0.95,
            'attributes' => array_filter([
                'spreadsheet_token' => $spreadsheetToken,
                'spreadsheet_url' => $spreadsheetUrl,
                'title' => $title,
                'source_work_action' => $workAction,
            ], static fn ($value) => ! ($value === '' || $value === null)),
        ];
    }

    /**
     * @param  array<string,mixed>  $raw
     * @return array<string,mixed>|null
     */
    private function extractBaseFocus(array $raw, string $workAction): ?array
    {
        $baseToken = $this->firstNonEmptyString($raw['base_token'] ?? null);
        $baseUrl = $this->firstNonEmptyString($raw['url'] ?? ($raw['base_url'] ?? null));
        $baseName = $this->firstNonEmptyString($raw['base_name'] ?? ($raw['name'] ?? null));
        if ($baseToken === '' && $baseUrl === '') {
            return null;
        }

        return [
            'object_type' => 'base',
            'object_id' => $baseToken !== '' ? $baseToken : $baseUrl,
            'summary' => $baseName !== '' ? $baseName : 'Recent Base',
            'confidence' => 0.94,
            'attributes' => array_filter([
                'base_token' => $baseToken,
                'base_url' => $baseUrl,
                'base_name' => $baseName,
                'source_work_action' => $workAction,
            ], static fn ($value) => ! ($value === '' || $value === null)),
        ];
    }

    /**
     * @param  array<string,mixed>  $raw
     * @return array<string,mixed>|null
     */
    private function extractMeetingFocus(array $raw, string $workAction): ?array
    {
        $rawData = is_array($raw['raw_data'] ?? null) ? $raw['raw_data'] : [];
        $items = $rawData['items'] ?? ($rawData['meetings'] ?? ($rawData['notes'] ?? []));
        if (! is_array($items) || $items === []) {
            return null;
        }

        $first = null;
        foreach ($items as $item) {
            if (is_array($item)) {
                $first = $item;
                break;
            }
        }
        if (! is_array($first)) {
            return null;
        }

        $meetingId = $this->firstNonEmptyString($first['meeting_id'] ?? ($first['id'] ?? ($first['minute_token'] ?? null)));
        $url = $this->firstNonEmptyString($first['url'] ?? ($first['note_url'] ?? null));
        $summary = $this->firstNonEmptyString($first['topic'] ?? ($first['title'] ?? null));
        if ($meetingId === '' && $url === '') {
            return null;
        }

        return [
            'object_type' => 'meeting',
            'object_id' => $meetingId !== '' ? $meetingId : $url,
            'summary' => $summary !== '' ? $summary : 'Recent meeting',
            'confidence' => 0.9,
            'attributes' => array_filter([
                'meeting_id' => $meetingId,
                'url' => $url,
                'summary' => $summary,
                'source_work_action' => $workAction,
            ], static fn ($value) => ! ($value === '' || $value === null)),
        ];
    }

    private function firstNonEmptyString(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        return trim((string) $value);
    }
}
