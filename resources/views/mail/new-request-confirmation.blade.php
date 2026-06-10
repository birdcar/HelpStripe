<x-mail::message>
# We received your request

Hi {{ $request->customer->name }},

Your request **#{{ $request->id }}** — {{ $request->subject }} — is in our queue.
Reply to this email at any time to add more detail; keeping
[#{{ $request->id }}] in the subject makes sure your reply reaches the
same request.

<x-mail::panel>
Your access key: **{{ $request->access_key }}**

Use it with your email address to check this request's status on our
self-service portal.
</x-mail::panel>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
