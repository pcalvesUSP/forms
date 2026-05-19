<?php

namespace Uspdev\Forms\Http\Controllers;

use Error;
use Exception;
use File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Uspdev\Forms\Models\FormDefinition;

class DefinitionController extends Controller
{

    public function __construct()
    {
        $this->middleware('can:' . config('uspdev-forms.adminGate'));
    }


    public function index()
    {
        \UspTheme::activeUrl(route('form-definitions.index'));

        $formDefinitions = FormDefinition::all();
        // Inidica a aba de 'index' como ativa na view
        $activeTab = 'index';
        return view('uspdev-forms::definition.index', compact('formDefinitions','activeTab'));
    }

    public function show(FormDefinition $formDefinition)
    {
        return $formDefinition;
    }

    public function create()
    {
        \UspTheme::activeUrl(route('form-definitions.index'));
        return view('uspdev-forms::definition.create', ['formDefinition' => null]);
    }

    public function store(Request $request)
    {
        $fields = json_decode($request->input('fields'), true);

        FormDefinition::create([
            'name'        => $request->input('name'),
            'group'       => $request->input('group'),
            'description' => $request->input('description'),
            'fields'      => $fields,
        ]);

        return redirect()->route('form-definitions.index')
            ->with('alert-success', 'Definição criada com sucesso!');
    }

    public function edit(FormDefinition $formDefinition)
    {
        \UspTheme::activeUrl(route('form-definitions.index'));
        return view('uspdev-forms::definition.create', compact('formDefinition'));
    }

    public function update(Request $request, FormDefinition $formDefinition)
    {
        $formDefinition->fields = json_decode($request->input('fields'), true);

        $formDefinition->save();

        $formDefinition->update($request->only(['name', 'group', 'description']));

        return redirect()->route('form-definitions.index')
            ->with('alert-success', 'Definição atualizada com sucesso!');
    }

    /**
     * Remove o registro do banco de dados
     *
     * Também remove registros excluidos (softDeletes) limpando o BD
     */
    public function destroy(FormDefinition $formDefinition, Request $request)
    {
        if ($request->destroy_trashed) {
            $formDefinition->formSubmissions()->onlyTrashed()->forceDelete();
            return redirect()->route('form-definitions.index')
                ->with('alert-success', 'Registros excluídos limpado com sucesso!');
        }

        try {
            $formDefinition->delete();
            return redirect()->route('form-definitions.index')
                ->with('alert-success', 'Definição excluída com sucesso!');
        } catch (Exception $e) {
            return redirect()->route('form-definitions.index')
                ->with('alert-danger', 'Não foi possível excluir: ' . $e->getMessage());
        }
    }
    /**
     * Gera o backup de uma definição de formulário.
     * Inicialmente, verifica a existência do diretório para salvar os arquivos .json,
     *      caso não exista, o cria.
     * Após a verificação, cria o arquivo com nome no formato: 'nomedoform@datadacriaçãodobackup.json'
     * Assim, abre o arquivo e escreve a definição no formato esperado do .json]
     *
     * @param FormDefinition $formDefinition
     * @return \Illuminate\Http\RedirectResponse
     */
    public function backup_def(FormDefinition $formDefinition)
    {

        $file_dir = config("uspdev-forms.forms_storage_dir");
        if(!is_dir($file_dir))
        {
            mkdir($file_dir,0777,true);
        }

        $file_path = $file_dir . "/" . $formDefinition['name'] . '@' . now()->format('d-m-Y_H:i:s') . ".json";
        $json_file = fopen($file_path, "w");

        fwrite($json_file, json_encode($formDefinition,JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        fclose($json_file);

        return redirect()->back()->with('alert-success','Backup de: '. $formDefinition['name'] .' gerado com sucesso em: ' . now() . '!');

    }

    /**
     * Gera um backup de todas as definições persisitidas no banco de dados
     * Apenas usa o método 'backup_def' para todas as definições
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function backup_all()
        {
            $form_definitions = FormDefinition::all();

            foreach($form_definitions as $form_definition)
            {
                $this->backup_def($form_definition);
            }

            return redirect()->back()->with('alert-success','Backups gerados em: ' . now() . ' com sucesso!');
        }

    /**
     * Exibe informações básicas sobre os backups e definições:
     *  Definição - número de backups desta definição
     *  e botões de ação para visualizar e gerar novos backups.
     *
     * @return \Illuminate\Contracts\View\View
     */
    public function backups_index()
    {
        \UspTheme::activeUrl(route('form-definitions.backups'));

        // Indica que a aba ativa atualmente é a de backups
        $activeTab = 'backup';
        $formDefinitions = FormDefinition::all();
        return view('uspdev-forms::definition.backup', compact('activeTab','formDefinitions'));
    }

    /**
     * Lista todos os backups de ua definição que existem atualmente
     *
     * @param FormDefinition $formDefinition
     * @return \Illuminate\Contracts\View\View
     */
    public function list_backups(FormDefinition $formDefinition)
    {
        // Percorre todos os backups existentes e filtra pelo nome (relacionados à $formDefinition->name)
        $bckp_files = scandir(config('uspdev-forms.forms_storage_dir'));
        $bckp_files = array_filter($bckp_files, function($filename) use ($formDefinition) { return str_contains($filename,$formDefinition->name); });

        $backup_data = [];

        // Percorre todos os backups da definition, recuperando a data de criação e a data da última alteração
        // todo: se nao estiver no formato esperado, pode gerar um erro, tratar isso. ex: sem @ ou sem extensão .json
        foreach($bckp_files as $filename)
        {
            $created_time = explode('@',$filename)[1];
            $created_time = explode('.',$created_time)[0];

            $last_mod_time = date('d-m-Y_H:i:s',filemtime(config('uspdev-forms.forms_storage_dir') .'/'.$filename));

            // Grava no formato: tempo_criado => tempo_ultima_mod
            $backup_data[$created_time] = $last_mod_time;
        }

        return view('uspdev-forms::definition.backup-list', ['formDefinition' => $formDefinition, 'backup_data' => $backup_data]);
    }

    /**
     * 'Restaura' um backup específico, subindo as alterações feitas no arquivo para o banco de dados
     * ou retornando a definição para o estado em que o backup se encontrava na data de criação
     *
     * @param FormDefinition $formDefinition
     * @param mixed $created_time
     * @return \Illuminate\Http\RedirectResponse
     */
    public function restore_backup(FormDefinition $formDefinition, string $created_time)
    {
        // Remonta o tempo de criaão no formato correto
        $created_time = str_replace(' - ','_',$created_time);
        $created_time = str_replace('/','-',$created_time);

        // Remonta o nome do arquivo seguindo a formatação nomeform@tempoquecrioubckp.json
        $filename = $formDefinition->name . '@' . $created_time . '.json';

        // Chama o comando 'form:sync', passando o camiho do arquivo desejado como parâmetro, restaurando apenas aquela definição, mantendo intacta as demais
        Artisan::call('form:sync',['--path' => config('uspdev-forms.forms_storage_dir') . '/' .$filename]);

        return redirect()->back()->with('alert-success','Backup de ' . $created_time . ' restaurado com sucesso !');
    }

    /**
     * Remove um arquivo de backup do diretório
     *
     * @param FormDefinition $formDefinition
     * @param string $created_time
     * @return \Illuminate\Http\RedirectResponse
     */
    public function remove_backup(FormDefinition $formDefinition, string $created_time)
    {
        $created_time = str_replace(' - ','_',$created_time);
        $created_time = str_replace('/','-',$created_time);

        // Remonta o nome do arquivo
        $filename = $formDefinition->name . '@' . $created_time . '.json';

        // Remonta o caminho completo do arquivo
        $filepath = config('uspdev-forms.forms_storage_dir') . '/' . $filename;

        // Caso o arquivo exista no caminho remontado anteriormente, o remove
        if(File::exists($filepath))
        {
            File::delete($filepath);
            return redirect()->back()->with('alert-warning','Backup ' . $filename . ' removido com sucesso.' );
        }

        // Caso contrário, exibe uma mensagem de erro.
        else
        {
            return redirect()->back()->with('alert-danger', 'Impossível remover ' . $filename .' => arquivo não existe.');
        }
    }

    /**
     * Remove todos os backups de uma definição, filtrando pelo nome
     *
     * @param FormDefinition $formDefinition
     * @return \Illuminate\Http\RedirectResponse
     */
    public function remove_def_backups(FormDefinition $formDefinition)
    {
        // Recupera o diretório em que os backups são salvos
        $file_dir = config('uspdev-forms.forms_storage_dir');

        // Filtra os arquivos pelos nomes que contém o nome da definição
        $files = array_filter(scandir($file_dir),function($filename) use ($formDefinition){return str_contains($filename,$formDefinition->name);});

        // Percorre todos os arquivos
        foreach($files as $filename)
        {
            // Reconstrói o caminho dos arquivo
            $filepath = $file_dir . '/' . $filename;

            // Verifica a existência e deleta em caso afirmativo
            if(File::exists($filepath));
            {
                File::delete($filepath);
            }
        }

        return redirect()->back()->with('alert-warning', 'Backups de ' . $formDefinition->name . ' removidos com sucesso.');
    }

    /**
     * Remove todos os backups de todas as definições
     * @return \Illuminate\Http\RedirectResponse
     */
    public function remove_all_backup()
    {
        // Recupera o diretório em que os arquivos são salvos
        $file_dir = config('uspdev-forms.forms_storage_dir');

        // Filtra para obter apenas os arquivos .json(evita '.' e '..', além de possível lixo)
        $files = array_filter(scandir($file_dir), function($file) { return str_contains($file,'.json'); });

        // Percorre todos os arquivos do diretório
        foreach($files as $filename)
        {
            // Reconstrói o caminho do arquivo
            $filepath = $file_dir . '/' . $filename;

            // Verifica se o mesmo existe, e o deleta em caso afirmativo
            if(File::exists($filepath))
            {
                File::delete($filepath);
            }
        }

        return redirect()->back()->with('alert-warning', 'Backups removidos com sucesso.');

    }
}
