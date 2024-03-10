@extends('security::layouts.bootstrap.app')

@section('content')
    <div class="mt-5">
        <div class="row mb-3">
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-body">
                        <div>
                            Attacks Detected
                        </div>
                        <div>
                            {{ $attacksDetected }}
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-body">
                        <div>
                            IPs Blocked
                        </div>
                        <div>
                            {{ $ipsBlocked }}
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-body">
                        <div>
                            Requests Blocked
                        </div>
                        <div>
                            {{ $requestsBlocked }}
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-12">
                <div class="card">
                    <div class="card-header">
                        Dashboard
                    </div>
                    <div class="card-body">
                        Welcome!
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
