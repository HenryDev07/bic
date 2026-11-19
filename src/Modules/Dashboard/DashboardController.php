<?php

declare(strict_types=1);

namespace Bic\Modules\Dashboard;

use Bic\Core\Controller;
use Bic\Core\Http\Response;

/**
 * Etapa 3: panel base con datos reales del propio sistema.
 * Los indicadores comerciales llegan con sus módulos (etapa 4g); no hay cifras de relleno.
 */
final class DashboardController extends Controller
{
    public function index(): Response
    {
        $businessId = $this->app->actor()['business_id'];
        $db = $this->app->db;

        return $this->render('dashboard::index', [
            'title' => 'Dashboard',
            'activeUsers' => (int) $db->value('SELECT COUNT(*) FROM users WHERE business_id = ? AND is_active = 1 AND deleted_at IS NULL', [$businessId]),
            'roleCount' => (int) $db->value('SELECT COUNT(*) FROM roles'),
            'business' => $db->first('SELECT name, base_currency, timezone FROM businesses WHERE id = ?', [$businessId]),
        ]);
    }
}
