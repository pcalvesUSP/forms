<?php

namespace Uspdev\Forms\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Uspdev\Forms\Form;
use Uspdev\Forms\Models\FormDefinition;
use Uspdev\Forms\Models\FormSubmission;

class SubmissionController extends Controller
{

    public function __construct()
    {
        $this->middleware('can:' . config('uspdev-forms.adminGate'));
    }

    public function index(FormDefinition $formDefinition)
    {
        \UspTheme::activeUrl(route('form-definitions.index'));

        $config = [
            'editable' => true,
            'name' => $formDefinition->name,
            'action' => route('form-submissions.store', $formDefinition->id),
        ];
        $form = new Form($config);
        $form->user = Auth::user();
        $form->admin = Gate::allows('manager', $form->user) ? true : false;

        return view('uspdev-forms::submission.index', compact('form', 'formDefinition'));
    }

    public function create(FormDefinition $formDefinition)
    {
        \UspTheme::activeUrl(route('form-definitions.index'));

        $config = [
            'key' => null,
            'action' => route('form-submissions.store', $formDefinition),
        ];
        $form = new Form($config);
        $formHtml = $form->generateHtml($formDefinition->name);

        return view('uspdev-forms::submission.edit', [
            'definition' => $formDefinition,
            'submission' => null,
            'formHtml' => $formHtml,
        ]);
    }

    public static function edit(FormDefinition $formDefinition, FormSubmission $formSubmission)
    {
        \UspTheme::activeUrl(route('form-definitions.index'));

        $formHtml = (new Form(['method' => 'PUT']))->generateHtml($formDefinition->name, $formSubmission);

        return view('uspdev-forms::submission.edit')->with([
            'formHtml' => $formHtml,
            'submission' => $formSubmission,
            'definition' => $formDefinition,
        ]);
    }

    public function store(FormDefinition $formDefinition, Request $request)
    {
        $submission = (new Form(['editable' => true]))->handleSubmission($request);

        if ($submission instanceof FormSubmission) {
            return redirect()->route('form-submissions.index', $formDefinition)
                ->with('alert-success', 'Submissão criada com sucesso!');
        }

        if (is_array($submission)) {
            $message = '';
            $errors = $submission['errors'];
            foreach ($errors->getMessages() as $campo => $mensagens) {
                $message .= $campo . ' - ' . $mensagens[0] . "\n";
            }
        } else {
            $message = e($submission);
        }

        return redirect()->back()->withInput()
            ->with('alert-danger', 'Erro: ' . $message);
    }

    public static function update(Request $request, FormDefinition $formDefinition, FormSubmission $formSubmission)
    {
        $submission = (new Form(['editable' => true]))
            ->updateSubmission($request, $formSubmission->id);

        if ($submission instanceof FormSubmission) {
            return redirect(route('form-submissions.index', $formDefinition))
                ->with('alert-success', 'Submissão atualizada com sucesso!');
        }

        if (is_array($submission)) {
            $message = '';
            $errors = $submission['errors'];
            foreach ($errors->getMessages() as $campo => $mensagens) {
                $message .= $campo . ' - ' . $mensagens[0] . "\n";
            }
        } else {
            $message = e($submission);
        }

        return redirect()->back()->withInput()
            ->with('alert-danger', 'Erro: ' . $message);
    }

    public static function destroy(FormDefinition $formDefinition, FormSubmission $formSubmission)
    {
        $form = (new Form())->deleteSubmission($formSubmission->id, Auth::user());

        return redirect(route('form-submissions.index', $formDefinition))
            ->with('alert-success', 'Submissão enviada para lixeira com sucesso!');
    }

    public function downloadFile($formDefinition, FormSubmission $formSubmission, $fieldName)
    {
        return (new Form())->downloadSubmissionFile($formSubmission, $fieldName);
    }
}
