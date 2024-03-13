<?php

namespace OzanKurt\Security\DataTables;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use OzanKurt\Security\Models\Ip;
use OzanKurt\Security\Models\Log;
use Yajra\DataTables\Services\DataTable;
use Yajra\DataTables\Html\Column;

class IpsDataTable extends DataTable
{
    public function dataTable($query)
    {
        $builder = datatables()->eloquent($query);

        $builder->addColumn('action', function (Ip $ip) {
            return 'actions';
        });

        $builder->editColumn('created_at', function (Ip $ip) {
            return $ip->created_at ? $ip->created_at->format('Y-m-d H:i:s') : 'n/a';
        });
        $builder->editColumn('updated_at', function (Ip $ip) {
            return $ip->updated_at ? $ip->updated_at->format('Y-m-d H:i:s') : 'n/a';
        });

        // https://yajrabox.com/docs/laravel-datatables/master/row-options
        $builder->setRowId('id');
        $builder->addIndexColumn();

        $builder->rawColumns([
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
        $model = config('security.database.ip.model', Ip::class);

        $query = $model::query();

        return $query;
    }

    public function html()
    {
        return $this->builder()
            ->setTableId('ipsDataTable')
            ->columns($this->getColumns())
            ->minifiedAjax(app('security')->route('ips.index', [
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
            Column::make('ip')->class('all'),
            Column::make('log_id')->class('all'),
            Column::make('is_blocked')->class('all'),
            Column::make('request_count')->class('none'),
            Column::make('created_at')->class('none'),
            Column::make('updated_at')->class('none'),
        ];
    }
}
