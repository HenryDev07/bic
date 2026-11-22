<?php

declare(strict_types=1);

namespace Bic\Modules\Ai;

use Bic\Core\Controller;
use Bic\Core\Http\Request;
use Bic\Core\Http\Response;
use Bic\Modules\Ai\Provider\AiProvider;
use DateTimeImmutable;
use DateTimeZone;

final class AiController extends Controller
{
    /** Los tests sustituyen el proveedor; en producción sale de la configuración. */
    public static ?AiProvider $override = null;

    private function provider(): AiProvider
    {
        return self::$override ?? ProviderFactory::make($this->app->config);
    }

    private function nowUtc(): string
    {
        return $this->app->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function dayStartUtc(): string
    {
        return $this->app->utcBoundary($this->app->localTime($this->nowUtc(), 'Y-m-d'));
    }

    private function tools(): AiTools
    {
        $biz = (int) $this->app->actor()['business_id'];
        $bounds = function (int $days): array {
            $today = $this->app->localTime($this->nowUtc(), 'Y-m-d');
            $from = (new DateTimeImmutable($today))->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
            $prev = (new DateTimeImmutable($from))->modify('-' . $days . ' days')->format('Y-m-d');

            return ['from' => $from, 'to' => $today, 'fromUtc' => $this->app->utcBoundary($from), 'toUtc' => $this->app->utcBoundary($today, 1), 'prevFromUtc' => $this->app->utcBoundary($prev)];
        };
        $slow = fn (string $_): string => (new DateTimeImmutable($this->nowUtc(), new DateTimeZone('UTC')))->modify('-' . $this->app->settings->int($biz, 'insight_slow_days') . ' days')->format('Y-m-d H:i:s');

        return new AiTools($this->app->db, $this->app->auditLogger, $biz, fn (string $p): bool => $this->app->can($p), $bounds, $slow);
    }

    private function assistant(): AiAssistant
    {
        return new AiAssistant($this->app->db, $this->app->auditLogger, $this->provider());
    }

    public function index(Request $request): Response
    {
        $a = $this->app->actor();
        $svc = $this->assistant();
        $cid = (int) $request->string('c', '');
        $messages = $cid > 0 ? $svc->messages((int) $a['business_id'], (int) $a['id'], $cid) : [];
        if ($cid > 0 && $messages === []) {
            $cid = 0;
        }
        $limit = $this->app->settings->int((int) $a['business_id'], 'ai_daily_limit');

        return $this->render('ai::index', [
            'title' => 'Asistente IA', 'conversations' => $svc->conversations((int) $a['business_id'], (int) $a['id']), 'messages' => $messages, 'cid' => $cid,
            'provider' => $this->provider()->name(), 'remaining' => max(0, $limit - $svc->usedToday((int) $a['business_id'], (int) $a['id'], $this->dayStartUtc())),
            'tools' => array_column($this->tools()->available(), 'name'),
        ]);
    }

    public function ask(Request $request): Response
    {
        $a = $this->app->actor();
        $cid = (int) $request->string('conversation_id', '');
        $r = $this->assistant()->ask($a, $cid > 0 ? $cid : null, (string) $request->string('message', ''), $this->tools(),
            $this->app->settings->int((int) $a['business_id'], 'ai_daily_limit'), $this->dayStartUtc(), $this->nowUtc());
        if ($r['status'] === 404) {
            $this->notFound();
        }
        if (!$r['ok']) {
            $this->flash('danger', (string) $r['error']);

            return $this->redirect('/ai' . ($cid > 0 ? '?c=' . $cid : ''));
        }

        return $this->redirect('/ai?c=' . $r['conversation_id']);
    }
}
