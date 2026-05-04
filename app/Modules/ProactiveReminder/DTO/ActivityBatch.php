<?php

namespace App\Modules\ProactiveReminder\DTO;

final class ActivityBatch
{
    /**
     * @param  array<int,array<string,mixed>>  $calendar
     * @param  array<int,array<string,mixed>>  $messages
     * @param  array<int,array<string,mixed>>  $documents
     * @param  array<int,array<string,mixed>>  $meetings
     * @param  array<int,array<string,mixed>>  $sheets
     * @param  array<int,array<string,mixed>>  $bitables
     * @param  array<int,array<string,mixed>>  $mails
     * @param  array<int,ActivityItem>  $items
     */
    public function __construct(
        private array $calendar = [],
        private array $messages = [],
        private array $documents = [],
        private array $meetings = [],
        private array $sheets = [],
        private array $bitables = [],
        private array $mails = [],
        private array $items = [],
    ) {
    }

    public function add(SourceCollectionResult $result): void
    {
        switch ($result->bucket) {
            case 'calendar':
                $this->calendar = array_merge($this->calendar, $result->records);
                break;
            case 'messages':
                $this->messages = array_merge($this->messages, $result->records);
                break;
            case 'documents':
                $this->documents = array_merge($this->documents, $result->records);
                break;
            case 'meetings':
                $this->meetings = array_merge($this->meetings, $result->records);
                break;
            case 'sheets':
                $this->sheets = array_merge($this->sheets, $result->records);
                break;
            case 'bitables':
                $this->bitables = array_merge($this->bitables, $result->records);
                break;
            case 'mails':
                $this->mails = array_merge($this->mails, $result->records);
                break;
        }

        $this->items = array_merge($this->items, $result->items);
    }

    /** @return array<int,array<string,mixed>> */
    public function calendar(): array { return $this->calendar; }

    /** @return array<int,array<string,mixed>> */
    public function messages(): array { return $this->messages; }

    /** @return array<int,array<string,mixed>> */
    public function documents(): array { return $this->documents; }

    /** @return array<int,array<string,mixed>> */
    public function meetings(): array { return $this->meetings; }

    /** @return array<int,array<string,mixed>> */
    public function sheets(): array { return $this->sheets; }

    /** @return array<int,array<string,mixed>> */
    public function bitables(): array { return $this->bitables; }

    /** @return array<int,array<string,mixed>> */
    public function mails(): array { return $this->mails; }

    /** @return array<int,ActivityItem> */
    public function items(): array { return $this->items; }

    /**
     * @param  array<int,string>  $blockedHashes
     */
    public function filterMessageHashes(array $blockedHashes): self
    {
        if ($blockedHashes === []) {
            return $this;
        }

        $hashMap = array_fill_keys(array_filter($blockedHashes), true);

        $messages = array_values(array_filter(
            $this->messages,
            static fn (array $message): bool => ! isset($hashMap[(string) ($message['text_hash'] ?? '')])
        ));

        $items = array_values(array_filter(
            $this->items,
            static function (ActivityItem $item) use ($hashMap): bool {
                if ($item->type !== 'message') {
                    return true;
                }

                return ! isset($hashMap[(string) ($item->payload['text_hash'] ?? '')]);
            }
        ));

        return new self(
            $this->calendar,
            $messages,
            $this->documents,
            $this->meetings,
            $this->sheets,
            $this->bitables,
            $this->mails,
            $items
        );
    }

    public function hasActivity(): bool
    {
        return $this->calendar !== []
            || $this->messages !== []
            || $this->documents !== []
            || $this->meetings !== []
            || $this->sheets !== []
            || $this->bitables !== []
            || $this->mails !== [];
    }

    /**
     * @return array{calendar:int,messages:int,documents:int,meetings:int,sheets:int,bitables:int,mails:int}
     */
    public function counts(): array
    {
        return [
            'calendar' => count($this->calendar),
            'messages' => count($this->messages),
            'documents' => count($this->documents),
            'meetings' => count($this->meetings),
            'sheets' => count($this->sheets),
            'bitables' => count($this->bitables),
            'mails' => count($this->mails),
        ];
    }
}
