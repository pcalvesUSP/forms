# Forms

Forms é uma biblioteca **uspdev** que permite gerar formulários dinâmicos a partir de definições armazenadas em banco de dados e, opcionalmente, persiste os resultados.

## Funcionalidades

- Gera formulários a partir de definições no BD;
- Processa a submissão dos formulários com validação e persistência;
- Mostra o resultado com views padrão;
- Possui crud completo para `admin`;
- Suporta estilos em Bootstrap 4 e 5;
- Integra com aplicações Laravel 11 em diante.

## Instalação

1. **Instale a biblioteca via Composer e publique as migrations**

```bash
composer require uspdev/forms
php artisan vendor:publish --tag=forms-migrations
php artisan migrate
```

2. **Menu na aplicação**

No arquivo `config/laravel-usp-theme.php`, adicione ou reposicione a chave uspdev-forms para mostrar o menu. Ele será visível apenas para administradores.

```php
[
    'key' => 'uspdev-forms',
],
```

## Configuração

Você pode personalizar as configurações do pacote modificando o arquivo `config/uspdev-forms.php`.

    php artisan vendor:publish --tag=forms-config

## Formulário demo

Popula o banco com um formulário de demonstração com vários tipos de campos, facilitando testes rápidos.

```bash
php artisan forms:demo
```

## Sincronização de formulários

Sincroniza definições de formulários em `.json` para a tabela `form_definitions` de forma idempotente (create ou update).

```bash
php artisan forms:sync
```

Por padrão, o comando lê os arquivos do diretório configurado em `uspdev-forms.forms_storage_dir`.

Opcionalmente, você pode informar outro diretório:

```bash
php artisan forms:sync --path=storage/app/formsJson
```
    
## Uso

1. **Crie uma entrada na tabela form_definitions**

2. Nome do formulário: nome único que identifica o formulário

3. Grupo: serve para agrupar vários formulários em implementações mais complexas

4. Descrição: campo livre sem uso específico no sistema

5. Campos: campos do formulário

    OBS.: **Os campos USP dependem do replicado**.

6. Em todos os campos pode ser definido a diretiva **validation_rule** com a validação dos campos Laravel conforme a documentação.

   OBS.: **Campos são validados pelo tipo definido em type.** Por exemplo, campos do tipo "number" serão validados pela validação do Laravel "numeric". 

* **texto de 1 linha**

```json
[
    {
      "name": "name",
      "type": "text",
      "label": "Nome (text)",
      "required": true,
      "validation_rule": "max:150"
    },
]
```
* **dois campos na mesma linha**

```json
  [
    {
      "name": "name",
      "type": "text",
      "label": "Nome (text)",
      "required": true
    },
    {
      "name": "email",
      "type": "email",
      "label": "Email (email)",
      "required": false,
      "validation_rule":"max:150"
    }
  ],
```

* select simples

```json
[
  {
    "name": "rating",
    "type": "select",
    "label": "Avaliação (select)",
    "options": [
      "1",
      "2",
      "3",
      "4",
      "5"
    ],
    "validation_rule":"exists:ranking,id"
  },
]
```

* textarea

```json
[
  {
    "name": "message",
    "type": "textarea",
    "label": "Mensagem (textarea)"
  },
]
```

* file (upload de arquivo)

```json
[
  {
    "name": "arquivo",
    "type": "file",
    "label": "Arquivo",
    "accept": ".pdf, image/*"
  },
]
```

* **pessoa-usp**

```json
[
  {
    "name": "codpes",
    "type": "pessoa-usp",
    "label": "Pessoa USP",
    "required": true
  },
]
```

* disciplina-usp

```json
[
  {
    "name": "coddis",
    "type": "disciplina-usp",
    "label": "Disciplina USP",
    "required": true
  },
]
```

* patrimonio-usp

```json
[
  {
    "name": "numpat",
    "type": "patrimonio-usp",
    "label": "Patrimônio USP",
    "required": true
  },
]
```

* local-usp

```json
[
  {
    "name": "codlocusp",
    "type": "local-usp",
    "label": "Local USP",
    "required": true
  },
]
```

* data 
  
```json
[
  {
    "name": "data",
    "type": "date",
    "label": "Campo de data",
    "required": true
  },
]
```

    FormDefinition::create($form);


2. **Gere o formulário na sua view:**

Use a classe FormGenerator para renderizar o formulário no seu template Blade:

```php
use Uspdev\Forms\Form;

$form = new Form($key = null, ['action' => route('forms.action')]);
$formHtml = $form->generateHtml('demo'); // conforme definido em $form

// ....
```

3. **Trate as submissões do formulário:**

No seu controller, trate a submissão do formulário salvando os dados no banco de dados:

```php
public function store(Request $request)
{
  $form = (new Form())->handleSubmission($request);
  
  // ....
}
```
OBS.: caso exista no $request a chave id ($request->id) o formulário anteriormente submetido com este id será atualizado.

**Caso existam erros de validação, o método retorna um array com as seguintes informações: [$validated['status'=>'error','errors'=>[dados do erro],'data'=>[dados preenchidos no formulário]],String HTMLFormulário preenchido]**

4. **Listar submissões**
Recupere todas as submissões em geral ou de um formulário específico:

```php
$allSubmissions = $form->listSubmission();

// Ou

$allFormNameSubmissions = $form->listSubmission('form-name');
```

5. **Obter submissão**
Recupere uma submissão específica pelo seu id:

```php
$formSubmission = $form->getSubmission($formSubmissionId);
```

6. **Download de arquivo**
Faça o download de um arquivo de uma submissão através do nome do campo:

```php
$formSubmission = $form->downloadSubmissionFile($formSubmission, $fieldName);
```
OBS.: Os arquivos são armazenados em storage/app/formsubmissions/&lt;ano&gt;/id&lt;00&gt;-&lt;hash&gt;.&lt;ext&gt;. Caso tenha problemas de download verifique se os arquivos estão no local correto.

## Campos

### Tipos

* pessoa-usp: campo tipo select que faz busca no replicado e retorna uma pessoa. nome do campo recomendado: codpes;
* disciplina-usp: campo tipo select que faz busca no replicado e retorna uma disciplina. nome do campo recomendado: coddis;
* patrimonio-usp: campo tipo select que faz busca no replicado e retorna um bem patrimoniado. nome do campo recomendado: numpat;
* local-usp: campo tipo select que faz busca no replicado e retorna um local da usp já formatado. nome do campo recomendado: codlocusp;
* data: data simples no formato dd/mm/aaaa;
* text: texto simples (linha única);
  * pode passar `"maxlength": 100` para limitar a quantidade de caracteres;
* number: campo numérico;
  * pode passar `"min"`, `"max"` e `"step"` para definir limites e incremento;
* email: valida campos email;
* select: precisa passar `options`;
* textarea: parágrafos;
* file: pode passar `"accept" : ".pdf, image/*"`;

### Atributos adicionais por campo

#### width para todos os campos

O atributo `width` define a largura do campo no grid do Bootstrap (`col-1` até `col-12`).

```json
{
  "type": "text",
  "name": "nome",
  "width": 6
}
```

HTML gerado:

```html
<div class="col-6">
  <input type="text" name="nome">
</div>
```

## Contribuindo

Contribuições são bem-vindas! Siga estes passos para contribuir:

- Faça um fork do repositório.
- Crie um novo branch (git checkout -b feature/SuaFuncionalidade).
- Faça suas alterações e commit (git commit -m 'Adiciona nova funcionalidade').
- Envie para o branch (git push origin feature/SuaFuncionalidade).
- Crie um novo Pull Request.


### Resumo do Conteúdo
- **Visão Geral do Pacote**: Descreve o que o pacote faz.
- **Funcionalidades**: Destaca as principais funcionalidades.
- **Passos de Instalação**: Fornece instruções detalhadas de instalação.
- **Detalhes de Configuração**: Guia sobre como personalizar as configurações.
- **Exemplos de Uso**: Mostra como criar um formulário YAML e usá-lo na sua aplicação.
- **Guia de Contribuição**: Incentiva contribuições com passos claros.
- **Informações de Licença**: Indica a licença






