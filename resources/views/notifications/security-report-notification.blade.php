{{-- @formatter:off --}}
@component('security::notifications.message')

{{-- Greeting --}}
# @lang('Hello!')

{!! $message !!}

Here is a summary of what happened since the last report:

<h1 class="section-title">
@lang('security::notifications.security_report.most_blocked_ips')
</h1>

<x-mail::table>
    <table class="rmf">
        <thead>
        <tr>
            <th class="text-left" style="width: 33%;">
                @lang('security::notifications.security_report.ip')
            </th>
            <th class="text-left" style="width: 33%;">
                @lang('security::notifications.security_report.country')
            </th>
            <th class="text-left">
                @lang('security::notifications.security_report.total_blocks')
            </th>
        </tr>
        </thead>
        <tbody>
        @foreach(range(1, 10) as $i)
            <tr>
                <td>{{ fake()->ipv4() }}</td>
                <td>
                    {{ Str::limit(fake()->country(), 25) }}
                </td>
                <td>{{ random_int(1, 100) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</x-mail::table>

<h1 class="section-title">
@lang('security::notifications.security_report.most_blocked_countries')
</h1>

<x-mail::table>
    <table class="rmf">
        <thead>
        <tr>
            <th class="text-left" style="width: 33%;">
                @lang('security::notifications.security_report.country')
            </th>
            <th class="text-left" style="width: 33%;">
                @lang('security::notifications.security_report.total_blocked_ips')
            </th>
            <th class="text-left">
                @lang('security::notifications.security_report.total_blocks')
            </th>
        </tr>
        </thead>
        <tbody>
        @foreach(range(1, 10) as $i)
            <tr>
                <td>
                    <span class="flag gb"></span>
                    {{ Str::limit(fake()->country(), 25) }}
                </td>
                <td>{{ random_int(1, 500) }}</td>
                <td>{{ random_int(1, 500) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</x-mail::table>

<h1 class="section-title">
@lang('security::notifications.security_report.most_failed_login_attempts')
</h1>

<x-mail::table>
    <table class="rmf">
        <thead>
        <tr>
            <th class="text-left" style="width: 33%;">
                @lang('security::notifications.security_report.user')
            </th>
            <th class="text-left" style="width: 33%;">
                @lang('security::notifications.security_report.login_attempts')
            </th>
            <th class="text-left">
                @lang('security::notifications.security_report.user_exists')
            </th>
        </tr>
        </thead>
        <tbody>
        @foreach(range(1, 10) as $i)
            <tr>
                <td>admin</td>
                <td>{{ random_int(1, 500) }}</td>
                <td>{{ ['Yes', 'No'][random_int(0, 1)] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</x-mail::table>

<h1 class="section-title">
@lang('security::notifications.security_report.last_modified_files')
</h1>

<x-mail::table>
    <table class="rmf">
        <thead>
            <tr>
                <th class="text-left" style="width: 25%;">
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

You can view the full report by clicking the button below:

@component('mail::button', ['url' => $actionUrl, 'color' => 'primary'])
    {{ $actionText }}
@endcomponent

Thank you for using **Laravel Shield**, you rock! 😎

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
