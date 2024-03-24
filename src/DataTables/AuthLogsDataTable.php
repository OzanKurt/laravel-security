<?php

namespace OzanKurt\Security\DataTables;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use OzanKurt\Security\Models\AuthLog;
use OzanKurt\Security\Models\Ip;
use OzanKurt\Security\Models\Log;
use Yajra\DataTables\Services\DataTable;
use Yajra\DataTables\Html\Column;

class AuthLogsDataTable extends DataTable
{
    public function dataTable($query)
    {
        $builder = datatables()->eloquent($query);

        $builder->addColumn('action', function (AuthLog $authLog) {
            return 'actions';
        });

        $nameField = config('security.dashboard.user_name_field');

        $builder->editColumn('user_name', function (AuthLog $authLog) use ($nameField) {
            return $log->user?->{$nameField} ?? 'Guest';
        });

        $baseUrl = url('/');

        $builder->editColumn('url', function (AuthLog $authLog) use ($baseUrl) {
            return str_replace($baseUrl, '', $authLog->url);
        });

        $builder->editColumn('request_data', function (AuthLog $authLog) {
            return app('security')->highlightJson($authLog->request_data);
        });

        $builder->editColumn('meta_data', function (AuthLog $authLog) {
            return app('security')->highlightJson($authLog->meta_data);
        });

        $builder->editColumn('created_at', function (AuthLog $authLog) {
            return $authLog->created_at ? $authLog->created_at->format('Y-m-d H:i:s') : 'n/a';
        });
        $builder->editColumn('updated_at', function (AuthLog $authLog) {
            return $authLog->updated_at ? $authLog->updated_at->format('Y-m-d H:i:s') : 'n/a';
        });

        // https://yajrabox.com/docs/laravel-datatables/master/row-options
        $builder->setRowId('id');
        $builder->addIndexColumn();

        $builder->rawColumns([
            'request_data',
            'actions',
        ]);

        $builder->filter(function ($query) {
            $filters = request('filters', []);

            if (empty($filters)) {
                return;
            }

            if (array_key_exists('roles', $filters)) {
                $query->whereIn('role_id', $filters['roles']);
            }

            $this->updateFilters();
        }, true);

        return $builder;
    }

    public function query(): Builder
    {
        $model = config('security.database.auth_log.model', AuthLog::class);

        $tableName = config('security.database.table_prefix').config('security.database.auth_log.table');
        $query = $model::query()
            ->select($tableName .'.*')
            ->with('user');

        return $query;
    }

    public function html()
    {
        return $this->builder()
            ->setTableId('authLogsDataTable')
            ->columns($this->getColumns())
            ->minifiedAjax(app('security')->route('auth-logs.index', [
                'mode' => 'dataTable',
            ]))
            ->orderBy(1)
            ->responsive(true)
            ->autoWidth(true)
            ->setTemplate('security::datatables.template');
    }

    protected function getColumns(): array
    {
        return [
            Column::make('id')
                ->title(trans('security::dashboard.columns.id'))
                ->class('all dtr-control'),
            Column::make('email')
                ->title(trans('security::dashboard.columns.email'))
                ->class('all'),
            Column::make('is_successful')
                ->title(trans('security::dashboard.columns.is_successful'))
                ->class('all'),
            Column::make('user_name', 'user.'.config('security.dashboard.user_name_field'))
                ->title(trans('security::dashboard.columns.user_name'))
                ->class('all'),
            Column::make('middleware')
                ->title(trans('security::dashboard.columns.middleware'))
                ->class('all'),
            Column::make('ip')
                ->title(trans('security::dashboard.columns.ip'))
                ->class('all'),
            Column::make('user_agent')
                ->title(trans('security::dashboard.columns.user_agent'))
                ->class('none'),
            Column::make('referrer')
                ->title(trans('security::dashboard.columns.referrer'))
                ->class('none'),
            Column::make('request_data')
                ->title(trans('security::dashboard.columns.request_data'))
                ->class('none'),
            Column::make('meta_data')
                ->title(trans('security::dashboard.columns.meta_data'))
                ->class('none'),
            Column::make('created_at')
                ->title(trans('security::dashboard.columns.created_at'))
                ->class('none'),
            Column::make('updated_at')
                ->title(trans('security::dashboard.columns.updated_at'))
                ->class('none'),
        ];
    }
}
