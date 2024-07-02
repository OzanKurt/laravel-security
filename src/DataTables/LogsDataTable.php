<?php

namespace OzanKurt\Security\DataTables;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use OzanKurt\Security\Models\AuthLog;
use OzanKurt\Security\Models\Ip;
use OzanKurt\Security\Models\Log;
use Yajra\DataTables\Services\DataTable;
use Yajra\DataTables\Html\Column;

class LogsDataTable extends DataTable
{
    public function dataTable($query)
    {
        $builder = datatables()->eloquent($query);

        $builder->addColumn('action', function (Log $log) {
            return 'actions';
        });

        $nameField = config('security.dashboard.user_name_field');

        $builder->editColumn('user_name', function (Log $log) use ($nameField) {
            return $log->user?->{$nameField} ?? 'Guest';
        });

        $baseUrl = url('/');

        $builder->editColumn('url', function (Log $log) use ($baseUrl) {
            return str_replace($baseUrl, '', $log->url);
        });

        $builder->editColumn('request_data', function (Log $log) {
            return app('security')->highlightJson($log->request_data);
        });

        $builder->editColumn('meta_data', function (Log $log) {
            return app('security')->highlightJson($log->meta_data);
        });

        $builder->editColumn('created_at', function (Log $log) {
            return $log->created_at ? $log->created_at->format('Y-m-d H:i:s') : 'n/a';
        });
        $builder->editColumn('updated_at', function (Log $log) {
            return $log->updated_at ? $log->updated_at->format('Y-m-d H:i:s') : 'n/a';
        });

        // https://yajrabox.com/docs/laravel-datatables/master/row-options
        $builder->setRowId('id');
        $builder->addIndexColumn();

        $builder->rawColumns([
            'request_data',
            'meta_data',
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
        $model = config('security.database.log.model', Log::class);

        $tableName = config('security.database.table_prefix').config('security.database.log.table');
        $query = $model::query()
            ->select($tableName .'.*')
            ->with('user');

        return $query;
    }

    public function html()
    {
        return $this->builder()
            ->setTableId('logsDataTable')
            ->columns($this->getColumns())
            ->minifiedAjax(app('security')->route('logs.index', [
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
            Column::make('user_name', 'user.'.config('security.dashboard.user_name_field'))
                ->title(trans('security::dashboard.columns.user_name'))
                ->class('all'),
            Column::make('middleware')
                ->title(trans('security::dashboard.columns.middleware'))
                ->class('all'),
            Column::make('level')
                ->title(trans('security::dashboard.columns.level'))
                ->class('all'),
            Column::make('ip')
                ->title(trans('security::dashboard.columns.ip'))
                ->class('all'),
            Column::make('url')
                ->title(trans('security::dashboard.columns.url'))
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
