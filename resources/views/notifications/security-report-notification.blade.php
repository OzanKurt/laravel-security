{{-- @formatter:off --}}
@component('security::notifications.message')

{{-- Intro Lines --}}
@foreach ($introLines as $line)
{{ $line }}

@endforeach

<h1 class="section-title">
    @lang('security::notifications.security_report.last_modified_files')
</h1>

<x-mail::table>
    <table class="rmf">
        <thead>
            <tr>
                <th class="text-left">
                    @lang('security::notifications.security_report.last_modification')
                </th>
                <th class="text-left">
                    @lang('security::notifications.security_report.file')
                </th>
            </tr>
        </thead>
        <tbody>
            @foreach($recentlyModifiedFiles as $file)
            <tr>
                <td class="white-space-nowrap">{{ date('Y-m-d H:i:s', $file[1]) }}</td>
                <td>{{ str_replace(base_path(), '', $file[0]) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</x-mail::table>

{{-- Outro Lines --}}
@foreach ($outroLines as $line)
{{ $line }}

@endforeach

{{-- Salutation --}}
@if (! empty($salutation))
{{ $salutation }}
@else
@lang('Regards'),<br>
{{ config('app.name') }}
@endif

{{-- Subcopy --}}
@isset($actionText)
@slot('subcopy')
@lang("If you're having trouble clicking the \":actionText\" button, copy and paste the URL below\n".
'into your web browser:', [
    'actionText' => $actionText,
])
<span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
@endslot
@endisset

{{-- Footer --}}
@slot('footer')
@component('mail::footer')
© {{ date('Y') }} **<a href="{{ config('app.url') }}" target="_blank">{{ config('app.name') }}</a>**<br>
@endcomponent
@endslot
@endcomponent
