<?php

declare(strict_types=1);

namespace Bic\Modules\Settings;

use Bic\Core\Controller;
use Bic\Core\Http\Request;
use Bic\Core\Http\Response;
use Bic\Core\Settings\SettingsService;

final class SettingsController extends Controller
{
    public function index(): Response
    {
        return $this->form($this->app->settings->all($this->app->actor()['business_id']), []);
    }

    public function update(Request $request): Response
    {
        $businessId = $this->app->actor()['business_id'];
        $input = [];
        foreach (array_keys(SettingsService::DEFINITIONS) as $key) {
            if (array_key_exists($key, $request->post)) { // lo no enviado conserva su valor
                $input[$key] = $request->post[$key];
            }
        }
        $result = $this->app->settings->update($businessId, $input);

        if ($result['errors'] !== []) {
            return $this->form($input + $result["old"], $result["errors"], 422);
        }
        if ($result['old'] !== $result['new']) {
            $this->app->audit('settings.update', 'settings', null, $result['old'], $result['new']);
        }
        $this->flash('success', 'Configuración guardada.');

        return $this->redirect('/settings');
    }

    /**
     * @param array<string,mixed> $values
     * @param array<string,string> $errors
     */
    private function form(array $values, array $errors, int $status = 200): Response
    {
        return $this->render('settings::index', [
            'title' => 'Configuración', 'values' => $values, 'errors' => $errors,
            'definitions' => SettingsService::DEFINITIONS, 'canEdit' => $this->app->can('settings.edit'),
        ], $status);
    }
}
