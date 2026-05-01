<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Run;

class RunStreamController extends Controller
{
    public function stream(Run $run)
    {
        return response()->stream(function () use ($run): void {
            $lastId = 0;
            $start = time();

            while (true) {
                $events = $run->events()->where('id', '>', $lastId)->orderBy('id')->get();
                foreach ($events as $event) {
                    $payload = [
                        'id' => $event->id,
                        'type' => $event->event_type,
                        'message' => $event->message,
                        'payload' => $event->payload,
                        'created_at' => $event->created_at?->toIso8601String(),
                    ];
                    echo "event: run_event\n";
                    echo 'data: '.json_encode($payload, JSON_UNESCAPED_UNICODE)."\n\n";
                    $lastId = (int) $event->id;
                }

                @ob_flush();
                @flush();

                $run->refresh();
                if (in_array($run->status, [Run::STATUS_SUCCESS, Run::STATUS_FAILED, Run::STATUS_WAITING_AUTH, Run::STATUS_NEEDS_INPUT], true) && $events->isEmpty()) {
                    break;
                }

                if ((time() - $start) > 120) {
                    break;
                }

                sleep(1);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
