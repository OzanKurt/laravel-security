<?php

namespace OzanKurt\Security\DataTables;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use OzanKurt\Security\Enums\IpEntryType;
use OzanKurt\Security\Models\Ip;
use OzanKurt\Security\Models\Log;
use Yajra\DataTables\Services\DataTable;
use Yajra\DataTables\Html\Column;

class IpsDataTable extends DataTable
{
    public function dataTable($query)
    {
        $builder = datatables()->eloquent($query);

        $builder->addColumn('actions', function (Ip $ip) {
            $links = [];

            if ($ip->entry_type == IpEntryType::BLOCK || $ip->entry_type == IpEntryType::BLACKLIST) {
                $whitelistRoute = app('security')->route('ips.action', [
                    'ip' => $ip,
                    'action' => 'whitelist',
                ]);
                $whitelistLink = <<<HTML
                    <a href="{$whitelistRoute}" class="btn btn-sm btn-primary ajax-link" title="Whitelist"
                        data-bs-toggle="tooltip" data-bs-title="Whitelist"
                    >
                        <i class="far fa-fw fa-check"></i>
                    </a>
                HTML;

                $links[] = $whitelistLink;
            }

            if ($ip->entry_type == IpEntryType::WHITELIST) {
                $blacklistRoute = app('security')->route('ips.action', [
                    'ip' => $ip,
                    'action' => 'blacklist',
                ]);
                $blacklistLink = <<<HTML
                    <a href="{$blacklistRoute}" class="btn btn-sm btn-warning ajax-link" title="Blacklist"
                        data-bs-toggle="tooltip" data-bs-title="Blacklist"
                    >
                        <i class="far fa-fw fa-times"></i>
                    </a>
                HTML;

                $links[] = $blacklistLink;
            }

            $deleteRoute = app('security')->route('ips.action', [
                'ip' => $ip,
                'action' => 'delete',
            ]);
            $deleteLink = <<<HTML
                <a href="{$deleteRoute}" class="btn btn-sm btn-danger ajax-link" title="Delete"
                    data-bs-toggle="tooltip" data-bs-title="Delete"
                >
                    <i class="far fa-fw fa-trash"></i>
                </a>
            HTML;

            $links[] = $deleteLink;

            return implode(' ', $links);
        });

        $builder->editColumn('entry_type', function (Ip $ip) {
            return $ip->entry_type ?? 'n/a';
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
            ->drawCallback('function() {
                window.initTooltips();
            }')
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
            Column::make('ip')
                ->title(trans('security::dashboard.columns.ip'))
                ->class('all'),
            Column::make('log_id')
                ->title(trans('security::dashboard.columns.log_id'))
                ->class('all'),
            Column::make('entry_type', 'entry_type')
                ->title(trans('security::dashboard.columns.entry_type'))
                ->class('all'),
            Column::make('request_count')
                ->title(trans('security::dashboard.columns.request_count'))
                ->class('all'),
            Column::make('created_at')
                ->title(trans('security::dashboard.columns.created_at'))
                ->class('none'),
            Column::make('updated_at')
                ->title(trans('security::dashboard.columns.updated_at'))
                ->class('none'),
            Column::make('actions')
                ->title(trans('security::dashboard.columns.actions'))
                ->class('all text-center'),
        ];
    }
}
