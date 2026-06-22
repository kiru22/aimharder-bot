<?php
namespace App\Console\Commands;

use App\Models\Account;
use App\Models\BookingLog;
use App\Models\BookingRule;
use App\Services\Aimharder\AimharderClient;
use App\Services\Aimharder\ClassMatcher;
use Illuminate\Console\Command;

class RunBookings extends Command
{
    protected $signature = 'bookings:run {--dry-run : Lista y empareja, sin reservar}';

    protected $description = 'Reserva las clases de hoy según las reglas activas';

    public function handle(): int
    {
        $today = now(config('aimharder.timezone'));
        $iso   = $today->dayOfWeekIso;          // 1=lun … 7=dom
        $day   = $today->format('Ymd');
        $dry   = (bool) $this->option('dry-run');

        $rules = BookingRule::with('account')
            ->where('active', true)
            ->get()
            ->filter(fn (BookingRule $r) => in_array($iso, $r->weekdays, true))
            ->filter(fn (BookingRule $r) => $r->account?->active);

        if ($rules->isEmpty()) {
            $this->info("Sin reglas para hoy ($day).");

            return self::SUCCESS;
        }

        foreach ($rules->groupBy('account_id') as $accountRules) {
            $account = $accountRules->first()->account;
            $this->processAccount($account, $accountRules, $day, $dry);
        }

        return self::SUCCESS;
    }

    private function processAccount(Account $account, $rules, string $day, bool $dry): void
    {
        $client = new AimharderClient($account->subdomain, $account->box_id);

        try {
            $client->login($account->email, $account->password, $account->fingerprint);
        } catch (\Throwable $e) {
            foreach ($rules as $rule) {
                $this->log($account, $rule, $day, null, 'failed', null, 'Login: '.$e->getMessage());
            }

            return;
        }

        $payload = $client->listClasses($day);

        foreach ($rules as $rule) {
            $match = ClassMatcher::find($payload, $rule->time, $rule->class_name);

            if ($match === null) {
                $this->log($account, $rule, $day, null, 'no_match', null,
                    "No se encontró {$rule->class_name} a las {$rule->time}.");

                continue;
            }

            $classId = (string) $match['id'];

            if (($match['bookState'] ?? null) === 1) {
                $this->log($account, $rule, $day, $classId, 'already', 1, 'Ya estaba reservada.');

                continue;
            }

            if ($dry) {
                $this->info("[dry-run] {$account->label}: reservaría {$rule->class_name} {$rule->time} (id $classId)");

                continue;
            }

            $res    = $client->book($classId, $day, $rule->insist);
            $state  = $res['bookState'] ?? null;
            $errLang = $res['errorMssgLang'] ?? null;
            $hasError = isset($res['errorMssg']) || isset($res['errorMssgLang']);

            if ($errLang === 'NOPUEDESRESERVAMISMAHORA') {
                $status = 'already';
            } elseif ($hasError) {
                $status = 'failed';
            } else {
                $status = 'booked';
            }

            $this->log($account, $rule, $day, $classId, $status, $state,
                $res['errorMssg'] ?? ($status === 'booked' ? 'Reservada correctamente' : "bookState=$state"));
        }
    }

    private function log(Account $a, BookingRule $r, string $day, ?string $classId, string $status, ?int $state, string $msg): void
    {
        BookingLog::create([
            'account_id'      => $a->id,
            'booking_rule_id' => $r->id,
            'target_date'     => $day,
            'class_id'        => $classId,
            'status'          => $status,
            'book_state'      => $state,
            'message'         => $msg,
        ]);

        $this->line("[$status] {$a->label} · {$r->class_name} {$r->time} · $msg");
    }
}
