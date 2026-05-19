<?php

namespace Uspdev\Forms\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Uspdev\Forms\Models\FormDefinition;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class FormSubmission extends Model
{
    use SoftDeletes;
    use LogsActivity;

    protected $guarded = ['id'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
        ];
    }

    /**
     * Relacionamento com FormDefinition
     */
    public function formDefinition(): BelongsTo
    {
        return $this->belongsTo(FormDefinition::class);
    }

    /**
     * Relacionamento com User
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Retorna as opções de auditoria da submissão.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['data'])
            ->setDescriptionForEvent(function (string $eventName) {
                $eventos = [
                    'created' => 'criada',
                    'updated' => 'atualizada',
                    'deleted' => 'excluída',
                ];
                $eventoPt = $eventos[$eventName] ?? $eventName;
                return "Submissão {$eventoPt}";
            });
    }

    /**
     * Renderiza o HTML de visualização do formulário enviado
     *
     * Utiliza a mesma regra de criação do formulário, lido do json.
     *
     * @param bool $longName Se true, exibe nome completo de campos como disciplina-usp (código + nome)
     * @param bool $isAdmin Se true, exibe e destaca campos administrativos (sem label)
     * @return string HTML renderizado com os dados do formulário
     */
    public function showHtml($longName = false, $isAdmin = false): string
    {
        $definition = $this->formDefinition;
        if (!$definition) {
            return '';
        }

        $fields = '';
        $adminFields = '';

        foreach ($definition->fields as $field) {
            if (array_is_list($field)) {
                // agrupando campos na mesma linha: igual para bs4 e bs5
                $row = '';
                foreach ($field as $f) {
                    $isAdminField = $this->isAdminField($f);
                    if ($isAdminField && !$isAdmin) {
                        continue;
                    }

                    if ($isAdminField) {
                        $adminFields .= $this->renderAdminField($f);
                        continue;
                    }

                    $label = trim((string) ($f['label'] ?? ''));
                    $content = '';
                    if ($label !== '') {
                        $content .= '<strong class="d-block mb-1">' . e($label) . '</strong>';
                    }
                    $content .= $this->renderField($f, $longName);

                    $row .= '<div class="col">' . $content . '</div>';
                }

                if (!empty($row)) {
                    $fields .= '<div class="row g-2 mb-3">' . $row . '</div>';
                }
            } else {
                // a linha possui um campo somente
                $isAdminField = $this->isAdminField($field);
                if ($isAdminField && !$isAdmin) {
                    continue;
                }

                if ($isAdminField) {
                    $adminFields .= $this->renderAdminField($field);
                    continue;
                }

                $label = trim((string) ($field['label'] ?? ''));
                $content = '';
                if ($label !== '') {
                    $content .= '<strong class="d-block mb-1">' . e($label) . '</strong>';
                }
                $content .= $this->renderField($field, $longName);

                $fields .= '<div class="mb-3">' . $content . '</div>';
            }
        }

        if ($isAdmin && !empty($adminFields)) {
            $fields .= '<div class="border border-danger rounded p-2 mb-3">'
                . $adminFields
                . '</div>';
        }

        return $fields;
    }

    /**
     * Renderiza campo administrativo em formato compacto: nome: valor
     */
    protected function renderAdminField(array $field): string
    {
        $fieldName = $field['name'];
        $value = $this->data[$fieldName] ?? null;
        $value = blank($value) ? 'n/a' : $value;

        return sprintf(
            '<div class="small mb-1"><span class="text-danger font-weight-bold">%s:</span> %s</div>',
            e($fieldName),
            e((string) $value)
        );
    }

    /**
     * Campos sem label são tratados como administrativos.
     */
    public function isAdminField(array $field): bool
    {
        return empty(trim((string) ($field['label'] ?? '')));
    }

    /**
     * Renderiza um campo individual no modo visualização
     *
     * Se for algum tipo em $types ele renderiza usando uma view especializada, caso contrário usa uma view genérica
     *
     * @param array $field Configuração do campo
     * @param bool $longName Se true, exibe informações completas do campo
     * @return string HTML renderizado do campo
     */
    public function renderField($field, $longName = false): string
    {
        $customViews = [
            'checkbox',
            'file',
            'pessoa-usp',
            'disciplina-usp',
            'patrimonio-usp',
            'local-usp',
        ];

        $field['id'] = 'uspdev-forms-' . ($field['name'] ?? 'field');

        $view = in_array($field['type'], $customViews, true)
            ? "uspdev-forms::partials.{$field['type']}-view"
            : 'uspdev-forms::partials.default-view';

        return view($view, ['field' => $field, 'submission' => $this, 'longName' => $longName,])->render();
    }
}
