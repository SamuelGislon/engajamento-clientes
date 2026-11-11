<x-mail::message>
# Nova atividade atribuída a você

**{{ $task->title }}**

@if ($task->company)
Empresa: {{ $task->company->name }}
@endif

Vencimento: {{ $task->due_at->timezone($timezone)->format('d/m/Y H:i') }}

<x-mail::button :url="$taskUrl">
Ver atividade
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
