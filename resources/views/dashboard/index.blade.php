@extends('security::layouts.bootstrap.app')

@section('content')
    <div class="mt-5">
        <div class="row mb-3">
            @if(session('outdated'))
                <div class="col-12">
                    <div class="alert alert-danger" role="alert">
                        <div class="alert-heading fw-bold fs-5">
                            @lang('security::dashboard.outdated_notification.title')
                        </div>
                        <div class="alert-content">
                            @lang('security::dashboard.outdated_notification.description')<br>
                            <br>
                            <code>
                                php artisan vendor:publish --provider="OzanKurt\Security\SecurityServiceProvider" --tag="security-assets"
                            </code>
                        </div>
                    </div>
                </div>
            @endif
            <div class="col-12"></div>
            <div class="col-lg-4">
                <div class="card shadow">
                    <div class="card-body position-relative overflow-hidden">
                        <div>
                            @lang('security::dashboard.attacks_blocked')
                        </div>
                        <div class="fs-4">
                            {{ $attacksDetected }}
                        </div>
                        <i class="far fa-7x fa-bug widget-icon"></i>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card shadow">
                    <div class="card-body position-relative overflow-hidden">
                        <div>
                            @lang('security::dashboard.ips_blocked')
                        </div>
                        <div class="fs-4">
                            {{ $ipsBlocked }}
                        </div>
                        <i class="far fa-7x fa-project-diagram widget-icon"></i>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card shadow">
                    <div class="card-body position-relative overflow-hidden">
                        <div>
                            @lang('security::dashboard.requests_blocked')
                        </div>
                        <div class="fs-4">
                            {{ $requestsBlocked }}
                        </div>
                        <i class="far fa-7x fa-shield-alt widget-icon"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-12">
                <div class="card shadow">
                    <div class="card-header">
                        @lang('security::dashboard.recently_modified_files')
                    </div>
                    <div class="card-body">
                        <table id="recently_modified_files_table" class="table">
                            <thead>
                                <tr>
                                    <th>
                                        @lang('security::dashboard.file')
                                    </th>
                                    <th>
                                        @lang('security::dashboard.last_modification')
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($recentlyModifiedFiles as $file)
                                    <tr>
                                        <td>
                                            {{ str_replace(base_path(), '', $file[0]) }}
                                        </td>
                                        <td data-order="{{ $file[1] }}">
                                            {{ date(config('security.dashboard.date_format'), $file[1]) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            $('#recently_modified_files_table').DataTable({
                "order": [[ 1, "desc" ]]
            });
        });
    </script>
@endpush
