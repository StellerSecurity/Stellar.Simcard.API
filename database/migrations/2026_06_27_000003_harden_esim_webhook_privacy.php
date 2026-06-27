<?php

use App\Services\Esim\EsimCryptoService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const REDACTED = '[REDACTED]';

    public function up(): void
    {
        $this->hardenSimcardsTable();
        $this->hardenWebhookEventsTable();
    }

    public function down(): void
    {
        // Intentionally irreversible: this migration removes plaintext sensitive identifiers
        // and raw provider payloads from durable storage.
    }

    private function hardenSimcardsTable(): void
    {
        if (!Schema::hasTable('simcards')) {
            return;
        }

        Schema::table('simcards', function (Blueprint $table) {
            if (!Schema::hasColumn('simcards', 'iccid_enc')) {
                $table->text('iccid_enc')->nullable()->after('external_order_id_hash');
            }

            if (!Schema::hasColumn('simcards', 'iccid_hash')) {
                $table->string('iccid_hash', 100)->nullable()->after('iccid_enc');
            }

            if (!Schema::hasColumn('simcards', 'iccid_last4')) {
                $table->string('iccid_last4', 4)->nullable()->after('iccid_hash');
            }
        });

        if (Schema::hasColumn('simcards', 'iccid')) {
            $crypto = app(EsimCryptoService::class);

            DB::table('simcards')
                ->whereNotNull('iccid')
                ->orderBy('id')
                ->chunkById(100, function ($rows) use ($crypto) {
                    foreach ($rows as $row) {
                        $iccid = trim((string) $row->iccid);

                        if ($iccid === '') {
                            continue;
                        }

                        DB::table('simcards')
                            ->where('id', $row->id)
                            ->update([
                                'iccid_enc' => $crypto->encryptSensitiveValue($iccid),
                                'iccid_hash' => $crypto->deriveIccidHash($iccid),
                                'iccid_last4' => $crypto->last4($iccid),
                            ]);
                    }
                });

            Schema::table('simcards', function (Blueprint $table) {
                $table->dropIndex(['iccid']);
                $table->dropColumn('iccid');
            });
        }
    }

    private function hardenWebhookEventsTable(): void
    {
        if (!Schema::hasTable('esim_webhook_events')) {
            return;
        }

        Schema::table('esim_webhook_events', function (Blueprint $table) {
            if (!Schema::hasColumn('esim_webhook_events', 'transaction_id_hash')) {
                $table->string('transaction_id_hash', 100)->nullable()->after('external_order_id_hash');
            }

            if (!Schema::hasColumn('esim_webhook_events', 'transaction_id_last4')) {
                $table->string('transaction_id_last4', 4)->nullable()->after('transaction_id_hash');
            }

            if (!Schema::hasColumn('esim_webhook_events', 'iccid_hash')) {
                $table->string('iccid_hash', 100)->nullable()->after('transaction_id_last4');
            }

            if (!Schema::hasColumn('esim_webhook_events', 'iccid_last4')) {
                $table->string('iccid_last4', 4)->nullable()->after('iccid_hash');
            }

            if (!Schema::hasColumn('esim_webhook_events', 'payload_redacted')) {
                $table->json('payload_redacted')->nullable()->after('status');
            }

            if (!Schema::hasColumn('esim_webhook_events', 'error_code')) {
                $table->string('error_code', 80)->nullable()->after('payload_redacted');
            }

            if (!Schema::hasColumn('esim_webhook_events', 'error_message')) {
                $table->string('error_message', 255)->nullable()->after('error_code');
            }
        });

        $crypto = app(EsimCryptoService::class);

        DB::table('esim_webhook_events')
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($crypto) {
                foreach ($rows as $row) {
                    $payload = $this->decodePayload($row->payload ?? $row->payload_redacted ?? null);
                    $content = is_array($payload['content'] ?? null) ? $payload['content'] : [];

                    $iccid = $this->nullableString($row->iccid ?? $content['iccid'] ?? null);
                    $transactionId = $this->nullableString($row->transaction_id ?? $content['transactionId'] ?? null);

                    DB::table('esim_webhook_events')
                        ->where('id', $row->id)
                        ->update([
                            'iccid_hash' => $iccid === null ? null : $crypto->deriveIccidHash($iccid),
                            'iccid_last4' => $crypto->last4($iccid),
                            'transaction_id_hash' => $transactionId === null ? null : $crypto->deriveTransactionHash($transactionId),
                            'transaction_id_last4' => $crypto->last4($transactionId),
                            'payload_redacted' => json_encode($this->redactArray($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                            'error_code' => isset($row->error) && $row->error !== null ? 'legacy_error_redacted' : null,
                            'error_message' => isset($row->error) && $row->error !== null ? 'Legacy error removed during privacy hardening.' : null,
                        ]);
                }
            });

        Schema::table('esim_webhook_events', function (Blueprint $table) {
            if (Schema::hasColumn('esim_webhook_events', 'transaction_id')) {
                $table->dropColumn('transaction_id');
            }

            if (Schema::hasColumn('esim_webhook_events', 'iccid')) {
                $table->dropColumn('iccid');
            }

            if (Schema::hasColumn('esim_webhook_events', 'payload')) {
                $table->dropColumn('payload');
            }

            if (Schema::hasColumn('esim_webhook_events', 'error')) {
                $table->dropColumn('error');
            }
        });
    }

    private function decodePayload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (is_string($payload) && $payload !== '') {
            $decoded = json_decode($payload, true);

            if (is_array($decoded)) {
                return $decoded;
            }
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
            'iccid',
            'orderno',
            'transactionid',
            'esimtranno',
            'esimtransactionno',
            'tranno',
            'transactionno',
            'timestamp',
            'eventtimestamp',
            'seqnumber',
            'sequencenumber',
            'imsi',
            'eid',
            'msisdn',
            'phone',
            'phonenumber',
            'email',
            'accesscode',
            'secretkey',
            'signature',
            'token',
            'authorization',
        ], true);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
};
