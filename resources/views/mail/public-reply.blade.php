{{--
    Markdown mailable view: Blade first, then CommonMark. {{ }} output is
    HTML-escaped by Blade as usual, and the result is rendered through
    Laravel's responsive mail layout (header, body card, footer). Keep
    text flush-left — indented lines become Markdown code blocks.
--}}
<x-mail::message>
{{ $note->body }}

<x-mail::subcopy>
Replying to this email adds your message to request #{{ $request->id }}.
</x-mail::subcopy>
</x-mail::message>
