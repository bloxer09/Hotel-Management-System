<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Services\PaymentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class BackfillPaymentLedger extends Command
{
    protected $signature = 'payments:backfill-ledger
        {--commit : Write reviewed legacy transactions to the ledger}
        {--report= : Optional CSV reconciliation report path}';

    protected $description = 'Preview or backfill legacy booking transactions into the append-only payment ledger';

    public function handle(PaymentService $payments): int
    {
        if (! Schema::hasTable('payments') || ! Schema::hasColumn('transactions', 'payment_id')) {
            $this->error('Payment-ledger migrations are not installed. Run migrations only after backup and review.');

            return self::FAILURE;
        }

        $commit = (bool) $this->option('commit');
        $rows = [];
        $created = 0;
        $skipped = 0;

        $query = Transaction::with('booking')
            ->whereNull('payment_id')
            ->where('amount', '>', 0)
            ->whereNotIn('payment_method', ['na', ''])
            ->orderBy('id');

        $query->chunkById(200, function ($transactions) use ($payments, $commit, &$rows, &$created, &$skipped) {
            foreach ($transactions as $transaction) {
                if (! $transaction->booking) {
                    $rows[] = $this->row($transaction, 'skip', 'Transaction has no booking.');
                    $skipped++;

                    continue;
                }

                $method = $transaction->payment_method;
                $reference = in_array($method, ['gcash', 'split'], true) ? $transaction->gcash_ref : $transaction->bank_ref;
                $reliable = $method === 'cash'
                    || ($method === 'gcash' && filled($reference))
                    || ($method === 'split'
                        && abs((float) $transaction->cash_amount + (float) $transaction->gcash_amount - (float) $transaction->amount) <= 0.01
                        && ((float) $transaction->gcash_amount <= 0 || filled($reference)));
                $status = $reliable ? 'verified' : 'pending';
                $note = $reliable
                    ? 'Reliable legacy collection.'
                    : 'Needs manual verification: missing/ambiguous electronic payment evidence.';

                $components = $this->components($transaction);
                $rows[] = $this->row($transaction, $commit ? $status : 'preview-'.$status, $note);

                if (! $commit) {
                    continue;
                }

                try {
                    $payment = $payments->record([
                        'received_at' => $transaction->created_at,
                        'payer_name' => $transaction->booking->booker_name ?: $transaction->booking->guest_name,
                        'payer_contact' => $transaction->booking->booker_contact ?: $transaction->booking->guest_contact,
                        'payment_method_code' => $method,
                        'reference_number' => $reference,
                        'amount' => (float) $transaction->amount,
                        'payment_type' => $this->paymentType($transaction),
                        'status' => $status,
                        'recorded_by' => $transaction->processed_by,
                        'verified_by' => $status === 'verified' ? $transaction->processed_by : null,
                        'verified_at' => $status === 'verified' ? $transaction->created_at : null,
                        'legacy_transaction_id' => $transaction->id,
                        'remarks' => "Backfilled from transaction #{$transaction->id}. {$note}",
                    ], [
                        $transaction->booking_id => (float) $transaction->amount,
                    ], $components, [
                        'skip' => true,
                        'skip_sync' => true,
                    ]);

                    $transaction->update(['payment_id' => $payment->id]);
                    $created++;
                } catch (\Throwable $e) {
                    $rows[count($rows) - 1]['result'] = 'conflict';
                    $rows[count($rows) - 1]['notes'] = $e->getMessage();
                    $skipped++;
                }
            }
        });

        if ($path = $this->option('report')) {
            $this->writeCsv($path, $rows);
            $this->info("Reconciliation report written to {$path}");
        }

        $this->table(
            ['Mode', 'Candidates', 'Created', 'Skipped'],
            [[$commit ? 'COMMIT' : 'DRY RUN', count($rows), $created, $skipped]]
        );
        $this->line($commit
            ? 'Backfill completed. Review pending payments in Front Desk Payments before verification.'
            : 'No records were changed. Re-run with --commit only after reviewing the output and taking a database backup.');

        return self::SUCCESS;
    }

    private function components(Transaction $transaction): array
    {
        $amount = round((float) $transaction->amount, 2);
        if ($transaction->payment_method !== 'split') {
            return [[
                'payment_method_code' => $transaction->payment_method,
                'amount' => $amount,
                'reference_number' => $transaction->payment_method === 'gcash' ? $transaction->gcash_ref : $transaction->bank_ref,
            ]];
        }

        $cash = round(max(0, (float) $transaction->cash_amount), 2);
        $gcash = round(max(0, $amount - $cash), 2);

        return [
            ['payment_method_code' => 'cash', 'amount' => $cash],
            ['payment_method_code' => 'gcash', 'amount' => $gcash, 'reference_number' => $transaction->gcash_ref],
        ];
    }

    private function paymentType(Transaction $transaction): string
    {
        if ($transaction->transaction_type === 'check_out') {
            return 'final';
        }
        if ($transaction->transaction_type === 'extension') {
            return 'partial';
        }
        if ($transaction->transaction_type === 'check_in') {
            return (float) $transaction->amount + 0.01 >= (float) $transaction->booking->total_amount
                ? 'full'
                : 'deposit';
        }

        return 'partial';
    }

    private function row(Transaction $transaction, string $result, string $notes): array
    {
        return [
            'transaction_id' => $transaction->id,
            'booking_id' => $transaction->booking_id,
            'booking_ref' => $transaction->booking?->booking_ref,
            'received_at' => optional($transaction->created_at)->format('Y-m-d H:i:s'),
            'method' => $transaction->payment_method,
            'amount' => (float) $transaction->amount,
            'reference' => $transaction->gcash_ref ?: $transaction->bank_ref,
            'result' => $result,
            'notes' => $notes,
        ];
    }

    private function writeCsv(string $path, array $rows): void
    {
        $directory = dirname($path);
        if (! is_dir($directory)) {
            throw new \RuntimeException("Report directory does not exist: {$directory}");
        }

        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException("Unable to create reconciliation report: {$path}");
        }

        $headers = ['transaction_id', 'booking_id', 'booking_ref', 'received_at', 'method', 'amount', 'reference', 'result', 'notes'];
        fputcsv($handle, $headers);
        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn ($header) => $row[$header] ?? null, $headers));
        }
        fclose($handle);
    }
}
