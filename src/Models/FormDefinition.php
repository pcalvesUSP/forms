<?php

namespace Uspdev\Forms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Uspdev\Forms\Models\FormSubmission;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Validator;

class FormDefinition extends Model
{
    protected $guarded = ['id'];

    /**
     * Get the attributes that should be cast. (Laravel 11 style)
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fields' => 'array',
        ];
    }

    /**
     * Sobrescreve o método boot do Eloquent Model.
     *
     * Registra o evento "saving" para validar os atributos da definição do formulário antes de salvar:
     * - name, group e description com regras básicas de string e tamanho
     * - fields como array obrigatório
     * - flatFields.*.name deve ser único e obrigatório
     *
     * Se algum campo não atender às regras, lança uma ValidationException.
     *
     * @return void
     * @throws \Illuminate\Validation\ValidationException
     */
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($model) {
            $rules = [
                'name'        => 'required|string|max:255|unique:form_definitions,name,' . $model->id,
                'group'       => 'required|string|max:255',
                'description' => 'nullable|string|max:255',
                'fields'      => 'required|array',
                'flat_fields.*.name' => 'required|string|distinct',
            ];

            $messages = [
                'flat_fields.*.name.distinct' => 'Os nomes dos campos devem ser únicos.',
                'flat_fields.*.name.required' => 'O nome de cada campo é obrigatório.',
            ];

            $data = $model->attributesToArray();
            $data['flat_fields'] = $model->flattenFields();

            $validator = Validator::make($data, $rules, $messages);

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }
        });
    }

    /**
     * Retorna fields mas achatado - sem subarrays
     */
    public function flattenFields()
    {
        $ret = [];
        foreach ($this->fields as $field) {
            if (array_is_list($field)) {
                foreach ($field as $f) {
                    $ret[] = $f;
                }
            } else {
                $ret[] = $field;
            }
        }
        return $ret;
    }

    /**
     * Filtro para buscar por campos específicos dentro do JSON de fields.
     */
    public function scopeFilter($query, string $key, mixed $value)
    {
        return $query->whereJsonContains("fields->{$key}", $value);
    }


    /**
     * Relacionamento com FormSubmission
     */
    public function formSubmissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }
}
