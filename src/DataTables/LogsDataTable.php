<?php

namespace OzanKurt\Security\DataTables;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
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

        $builder->editColumn('created_at', function (Log $log) {
            return $log->created_at ? $log->created_at->format('Y-m-d H:i:s') : 'n/a';
        });
        $builder->editColumn('updated_at', function (Log $log) {
            return $log->updated_at ? $log->updated_at->format('Y-m-d H:i:s') : 'n/a';
        });

        $builder->editColumn('request_data', function (Log $log) {
            $dataJson = json_encode($log->request_data, JSON_PRETTY_PRINT);
            $dataJson = htmlspecialchars($dataJson, ENT_QUOTES, 'UTF-8');

            return '<pre class="mb-0">' . $dataJson . '</pre>';
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
        $query = Log::query();

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
            Column::make('id')->class('all dtr-control'),
            Column::make('user_id')->class('all'),
            Column::make('middleware')->class('all'),
            Column::make('level')->class('all'),
            Column::make('ip')->class('all'),
            Column::make('url')->class('all'),
            Column::make('user_agent')->class('none'),
            Column::make('referrer')->class('none'),
            Column::make('request_data')->class('none'),
            Column::make('created_at')->class('none'),
            Column::make('updated_at')->class('none'),
        ];
    }
}
