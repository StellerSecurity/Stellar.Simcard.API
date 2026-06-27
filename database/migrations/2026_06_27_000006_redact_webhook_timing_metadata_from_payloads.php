<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const REDACTED = '[REDACTED]';

    /**
     * Redact provider-supplied timing/correlation metadata from already-redacted
     * webhook audit payloads. We keep received_at/processed_at as our audit timing.
     * Intentionally one-way: we never restore sensitive metadata values.
     */
    public function up(): void
    {
        DB::table('esim_webhook_events')
            ->select(['id', 'payload_redacted'])
            ->orderBy('id')
            ->chunkById(100, function ($events): void {
                foreach ($events as $event) {
                    $payload = $this->decodePayload($event->payload_redacted);

                    if ($payload === []) {
                        continue;
                    }

                    DB::table('esim_webhook_events')
                        ->where('id', $event->id)
                        ->update([
                            'payload_redacted' => json_encode(
                                $this->redactArray($payload),
                                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                            ),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Irreversible by design. Provider timing/correlation metadata is not restored.
    }

    private function decodePayload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (is_string($payload) && $payload !== '') {
            $decoded = json_decode($payload, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function redactArray(array $value): array
    {
        $redacted = [];

        foreach ($value as $key => $item) {
            if ($this->isSensitivePayloadKey((string) $key)) {
                $redacted[$key] = self::REDACTED;
                continue;
            }

            $redacted[$key] = is_array($item) ? $this->redactArray($item) : $item;
        }

        return $redacted;
    }

    private function isSensitivePayloadKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', '_'], '', $key));

        return in_array($normalized, [
            'timestamp',
            'eventtimestamp',
            'seqnumber',
            'sequencenumber',
        ], true);
    }
};
