@extends('security::layouts.bootstrap.app')

@section('content')
    <div class="mt-5">
        <div class="row">
            <div class="col-lg-12">
                <div class="card shadow">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <div>
                            @lang('security::dashboard.auth_logs') ({{ $authLogsCount }})
                        </div>
                        <div>
{{--                            <a href="{{ app('security')->route('auth-logs.index') }}" class="btn btn-sm btn-danger">--}}
{{--                                Clear Logs--}}
{{--                            </a>--}}
                        </div>
                    </div>
                    <div class="card-body">
                        {!! $dataTable->table([
                            'class' => 'table table-striped table-bordered'
                        ]) !!}
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    {!! $dataTable->scripts() !!}

    <script>
        $(function() {
            //
        });
    </script>
@endpush
