<?php

namespace Uspdev\Forms;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Uspdev\Forms\Models\FormDefinition;
use Uspdev\Forms\Models\FormSubmission;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\Models\Activity;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class Form
{
    /** Chave definida pelo usuário para esta instancia do form */
    public $key;

    public $group;
    public $btnLabel;
    public $btnSize;

    /** formDefinition object */
    public $definition;

    /** Metodo do form */
    public $method;

    /** corresponde ao campo action do formulário */
    public $action;

    /** Nome do formulario no BD*/
    public $name;

    /** se true, pode ser editado. nesse caso precisa passar o id da submissão */
    public $editable; // bool

    public $user;

    public $admin;

    public function __construct($config = [])
    {
        $this->key = isset($config['key']) ? $config['key'] : config('uspdev-forms.defaultKey');
        $this->method = isset($config['method']) ? $config['method'] : config('uspdev-forms.defaultMethod');

        $this->group = config('uspdev-forms.defaultGroup');
        $this->btnLabel = config('uspdev-forms.defaultBtnLabel');
        $this->btnSize = config('uspdev-forms.formSize') == 'small' ? ' btn-sm ' : '';

        // nome do form definition
        $this->name = isset($config['name']) ? $config['name'] : null;

        $this->action = isset($config['action']) ? $config['action'] : null;
        $this->editable = isset($config['editable']) ? $config['editable'] : false;
    }

    /**
     * Processa submissões do form persistindo em banco de dados
     *
     * Faz a validação dos campos do form
     * Dentro do request precisa ter form_definition, form_key
     * user é opcional para o caso do form ser aberto
     *
     * @param $request->form_definition
     * @param $request->form_key
     * @param $request->user
     * @param $request->id (necessário se update for permitido)
     * @return FormSubmission $formSubmission
     */
    public function handleSubmission(Request $request)
    {
        if (!$this->editable) {
            return 'Form não editável. Passe editable=true';
        }

        // Retrieve the form definition by id
        if (!($definition = $this->getDefinition($request->form_definition))) {
            return 'Erro ao buscar formDefinition';
        }

        // Lets store only valid form fields
        $validated = $this->validate($request);

        if ($validated['status'] === 'error') {
            return $validated;
        }

        $data = $validated['data'];

        if ($request->id) {
            // atualiza registro existente
            $form = FormSubmission::where('id', $request->id)->firstOrFail();
        } else {
            // cria novo registro
            $form = FormSubmission::Create([
                'form_definition_id' => $definition->id,
                'user_id' => $request->user() ? $request->user()->id : null,
                'key' => $request->form_key,
                'data' => [],
            ]);
        }

        $data = array_merge($form->data, $data);

        // remove arquivo existente
        if ($request->has('remover')) {
            foreach ($request->remover as $fieldName) {
                if (isset($form->data[$fieldName])) {
                    $filePath = $form->data[$fieldName]['stored_path'];
                    $deleted = Storage::disk('local')->delete($filePath);
                    // tratar erro se não conseguir remover o arquivo, geralmente por problemas de permissão
                    unset($data[$fieldName]);
                }
            }
        }

        // trata upload de arquivos (novos ou substituindo existentes)
        if ($request->hasFile('file')) {
            foreach ($request->file('file') as $fieldName => $file) {
                if (isset($form->data[$fieldName])) {
                    // se já existe um arquivo nesse campo, vamos remover o antigo
                    $filePath = $data[$fieldName]['stored_path'];
                    $deleted = Storage::disk('local')->delete($filePath);
                    // tratar erro se não conseguir remover o arquivo, geralmente por problemas de permissão
                    unset($data[$fieldName]);
                }
                $fileHash = md5_file($file->path());
                $extension = $file->getClientOriginalExtension();
                $name = $file->getClientOriginalName();
                $originalName = Str::slug(pathinfo($name, PATHINFO_FILENAME)) . '.' . $extension;

                $storedName = 'id' . $form->id . '-' . $fileHash . '.' . $extension;
                $path = $file->storeAs('formsubmissions/' . date('Y'), $storedName, 'local');
                if (!$path) {
                    // tratar erro se não conseguir salvar o arquivo, geralmente por problemas de permissão
                }
                $data[$fieldName] = [
                    'original_name' => $originalName,
                    'stored_path' => $path,
                    'content_hash' => $fileHash,
                ];
            }
        }

        $form->data = $data;
        $form->save();

        return $form;
    }

    /**
     * Validate form submission and return standardized response.
     *
     * @return array
     */
    public function validate($request)
    {
        if (!($definition = $this->getDefinition($request->form_definition))) {
            return [
                'status' => 'error',
                'message' => 'Erro ao buscar o formDefinition',
            ];
        }

        $rules = $this->getValidationRules($definition);
        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return [
                'status' => 'error',
                'errors' => $validator->errors(),
            ];
        }

        return [
            'status' => 'success',
            'data' => $request->only(array_keys($rules)),
        ];
    }

    /**
     * Retorna as regras de validação para os campos do form
     */
    protected static function getValidationRules(FormDefinition $definition)
    {
        $rules = [];

        foreach ($definition->fields as $field) {
            if (array_is_list($field)) {
                foreach ($field as $f) {
                    if($f['type'] == 'file')
                    {
                        $key = 'file.' . $f['name'];
                    }
                    else {$key = $f['name'];}
                    $rules[$key] = self::getFieldValidationRule($f);
                }
            } else {
                if($field['type'] == 'file')
                    {
                        $key = 'file.' . $field['name'];
                    }
                    else {$key = $field['name'];}
                $rules[$key] = self::getFieldValidationRule($field);
            }
        }
        return $rules;
    }

    /**
     * Return the validation rule for a field based on required or type
     */
    protected static function getFieldValidationRule($field)
    {
        // required or nullable
        $rule = !empty($field['required']) ? 'required' : 'nullable';

        $options = $field['options'] ?? [];
        $options = is_array($options) ? $options : [];
        $values = [];
        if (!empty($options)) {
            $values = array_map(function ($option) {
                return is_array($option) && isset($option['value']) ? $option['value'] : $option;
            }, $options);
        }

        $rulesMap = [
            'email' => 'email',
            'number' => 'numeric',
            'date' => 'date',
            'url' => 'url',
            'file' => 'file',
            'select.*' =>  ['in:' . implode(',', $values)]
        ];

        if (isset($rulesMap[$field['type']])) {
            
            $rule .= '|' . $rulesMap[$field['type']];
        }

        return $rule;
    }

    protected static function addFieldGenParams($field)
    {
        $field['bs'] = config('uspdev-forms.bootstrapVersion');
        $field['required'] = isset($field['required']) ? $field['required'] : false;
        $field['requiredLabel'] = $field['required'] ? ' <span class="text-danger">*</span>' : '';
        $field['formGroupClass'] = $field['bs'] == 5 ? 'mb-3' : 'form-group';
        $field['controlClass'] = 'form-control ' . (config('uspdev-forms.formSize') == 'small' ? ' form-control-sm ' : '');
        $field['id'] = 'uspdev-forms-' . $field['name'];

        $field['old'] = null;

        return $field;
    }

    /**
     * Generates HTML FORM from Form Definition
     *
     * @param String $formName ID of form definition
     * @return String HTML formatted
     */
    public function generateHtml(?string $formName = null, $formSubmission = null)
    {
        // pega  pela definição
        if (!($this->definition = $this->getDefinition($formName ?? $this->name)) && !($this->definition = $formSubmission->formDefinition)) {
            return null;
        }

        $fields = '';
        foreach ($this->definition->fields as $field) {
            $has_sep = false;

            if (array_is_list($field)) {
                
                // Verifica se há a necessidade de um separador entre esta linha e a anteriror
                if($field[0]['type'] == 'separator')
                {
                    $fields .= 
                    '<div class="d-flex align-items-center mt-5 mb-2">
                        <h6 class="text-secondary mr-2 ">
                            <strong>'. ($field[0]['label'] ?? '') .'</strong>
                        </h6>
                        <div class="flex-grow-1 border mb-2"></div>
                    </div>';
                }

                // agrupando campos na mesma linha: igual para bs4 e bs5
                $fields .= '<div class="row">';
                
                foreach ($field as $f) {
                    if($f['type'] != 'separator'){
                        $colClass = 'col';
                        if (isset($f['width']) && is_numeric($f['width'])) {
                            $width = (int) $f['width'];
                            if ($width >= 1 && $width <= 12) {
                                $colClass = 'col-' . $width;
                            }
                        }
                        $fields .= '<div class="' . $colClass . '">' . $this->generateField($f, $formSubmission) . '</div>';
                    }
                }
                $fields .= '</div>';
                
            } else {
                // a linha possui um campo somente
                if (isset($field['width']) && is_numeric($field['width'])) {
                    $width = (int) $field['width'];
                    if ($width >= 1 && $width <= 12) {
                        $fields .= '<div class="col-' . $width . '">' . $this->generateField($field, $formSubmission) . '</div>';
                        continue;
                    }
                }

                $fields .= $this->generateField($field, $formSubmission);
            }
        }
        if ($formSubmission) {
            $this->btnLabel = 'Atualizar';
        }

        return view('uspdev-forms::partials.form', [
            'form' => $this,
            'fields' => $fields,
        ])->render();
    }

    /**
     * Generates fields for the form generator
     */
    protected function generateField($field, $formSubmission)
    {
        // tipos de entradas do form conhecidos
        $types = ['textarea', 'select', 'checkbox', 'hidden', 'time', 'date', 'file', 'pessoa-usp', 'disciplina-usp', 'patrimonio-usp', 'local-usp'];

        $field = Form::addFieldGenParams($field);

        if (isset($formSubmission->data[$field['name']])) {
            $field['old'] = $formSubmission->data[$field['name']];
        }

        // vamos escolher o template do input com base no 'type'
        if (in_array($field['type'], $types)) {
            $html = view('uspdev-forms::partials.' . $field['type'], compact('field'))->render();
        } else {
            $html = view('uspdev-forms::partials.default', compact('field'))->render();
        }

        return $html;
    }

    /**
     * List form submissions filtering by key and optionally by formName
     *
     * If there's no specific key, it lists all submissions
     */
    public function listSubmission($formName = null)
    {
        $cond = [];
        if ($this->key != config('uspdev-forms.defaultKey')) {
            $cond['key'] = $this->key;
        }

        if ($formName) {
            $cond['form_definition_id'] = $this->getDefinition($formName)->id;
        }

        return FormSubmission::where($cond)->get();
    }

    /**
     * List form submissions filtering by the value of a given field
     */
    public function whereSubmissionContains($field, $string)
    {
        if ($this->admin == true) {
            return FormSubmission::all();
        } else {
            return FormSubmission::whereJsonContains('data->' . $field, (string) $string)->get();
        }
    }

    /**
     * Lista as submissões com filtro
     * 
     * @param string $field Nome do campo do json dentro de data a ser filtrado
     * @param string $operator Operador de comparação. Suporta: contains, =, ==, !=, empty, not_empty
     * @param mixed $value Valor a ser comparado. Pode ser string, array ou null
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function filterSubmissionByField($field, $operator, $value = null)
    {
        $jsonField = "data->$field";

        $query = new FormSubmission;
        if ($this->name) {
            $query::where('form_definition_id', $this->getDefinition($this->name)->id);
        }
        if ($this->key) {
            $query::where('key', $this->key);
        }

        switch ($operator) {
            case 'contains':
                // valor dentro do JSON (array ou string)
                return $query::whereJsonContains($jsonField, (string) $value)->get();

            case '=':
            case '==':
                return $query::where($jsonField, $value)->get();

            case '!=':
                return $query::where($jsonField, '!=', $value)->get();

            case 'empty':
                return $query::where(function ($query) use ($jsonField) {
                    $query->whereNull($jsonField)
                        ->orWhere($jsonField, '');
                })->get();

            case 'not_empty':
                return $query::where(function ($query) use ($jsonField) {
                    $query->whereNotNull($jsonField)
                        ->where($jsonField, '!=', '');
                })->get();

            default:
                throw new \InvalidArgumentException("Operador '$operator' não suportado.");
        }
    }


    /**
     * Get a form submission by id
     */
    public function getSubmission($id)
    {
        return FormSubmission::find($id);
    }

    /**
     * Get a form submission activities by id
     */
    public function getSubmissionActivities($id)
    {
        return Activity::orderBy('created_at', 'DESC')->where('subject_id', $id)->take(20)->get();
    }

    /**
     * Updates a form submission and registers the activity
     */
    public function updateSubmission(Request $request, $formSubmissionId)
    {
        if ($this->editable) {
            $request->id = $formSubmissionId;
            $formSubmission = $this->handleSubmission($request);

            return $formSubmission;
        }
        return false;
    }

    /**
     * Downloads a file from a form submission given the field name
     */
    public function downloadSubmissionFile(FormSubmission $formSubmission, $fieldName)
    {
        $path = $formSubmission->data[$fieldName]['stored_path'] ?? null;
        if (!Storage::exists($path)) {
            return abort(404, 'Arquivo não encontrado');
        }

        $nomeDownload = preg_replace('/[\x00-\x1F\x7F\/\\\\]/', '-', basename($path));
        $nomeDownload = $formSubmission->data[$fieldName]['original_name'] ?? $nomeDownload;

        return response()->download(Storage::path($path), null, [
            'Content-Type' => Storage::mimeType($path),
        ])->setContentDisposition('attachment', $nomeDownload);
    }

    /**
     * Deletes a form submission and registers the activity
     */
    public function deleteSubmission($id, $user = null)
    {
        $user = $user ?? Auth::user();
        $submission = $this->getSubmission($id);

        $mockSubmission = $submission;
        if ($submission->delete()) {
            activity()->performedOn($mockSubmission)->causedBy($user)->log('Chave excluída');
            return $mockSubmission;
        }
        return false;
    }

    /**
     * Returns form definition by form name
     */
    public function getDefinition($formName = null)
    {
        return FormDefinition::where('name', $formName ?? $this->name)->first();
    }

    /**
     * Return form definitions for a group
     */
    public function listDefinition($formGroup = null)
    {
        $where[] = $formGroup ? ['group', $formGroup] : ['group', $this->group];
        return FormDefinition::where($where)->get();
    }

    public function detailActivity($id)
    {
        return Activity::findOrFail($id);
    }
}
