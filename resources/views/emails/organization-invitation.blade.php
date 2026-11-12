<x-mail::message>
# Você recebeu um convite

{{ $organizationName }} convidou você para participar como {{ mb_strtolower($roleLabel) }}@if($teamName) na equipe {{ $teamName }}@endif.

<x-mail::button :url="$invitationUrl">
Aceitar convite
</x-mail::button>

O convite expira em sete dias e só pode ser utilizado pelo endereço de e-mail que o recebeu. Se não esperava este convite, ignore esta mensagem.

{{ config('app.name') }}
</x-mail::message>
