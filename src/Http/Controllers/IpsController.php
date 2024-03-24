<?php

namespace OzanKurt\Security\Http\Controllers;

use App\Http\Controllers\Controller;
use OzanKurt\Security\DataTables\IpsDataTable;
use OzanKurt\Security\Enums\IpEntryType;
use OzanKurt\Security\Models\Ip;

class IpsController extends Controller
{
    public function index()
    {
        $dataTable = app(IpsDataTable::class);

        if (request('mode') === 'dataTable' && request()->ajax()) {
            return $dataTable->ajax();
        }

        $ipsCount = Ip::count();

        return view('security::dashboard.ips.index')->with([
            'ipsCount' => $ipsCount,
            'dataTable' => $dataTable->html(),
        ]);
    }

    public function postAction(Ip $ip)
    {
        $action = request('action');

        if ($action === 'whitelist') {
            $ip->entry_type = IpEntryType::WHITELIST;
            $ip->request_count = 0;
            $ip->save();

            return response()->json([
                'actions' => [
                    [
                        'type' => 'toastr',
                        'data' => [
                            'type' => 'success',
                            'title' => trans('security::responses.ip.whitelisted.title'),
                            'message' => trans('security::responses.ip.whitelisted.message'),
                        ],
                    ],
                    [
                        'type' => 'reloadDataTable',
                        'data' => [
                            'dataTableId' => 'ipsDataTable',
                        ],
                    ],
                ],
            ]);
        }

        if ($action === 'blacklist') {
            $ip->entry_type = IpEntryType::BLACKLIST;
            $ip->request_count = 0;
            $ip->save();

            return response()->json([
                'actions' => [
                    [
                        'type' => 'toastr',
                        'data' => [
                            'type' => 'success',
                            'title' => trans('security::responses.ip.blacklisted.title'),
                            'message' => trans('security::responses.ip.blacklisted.message'),
                        ],
                    ],
                    [
                        'type' => 'reloadDataTable',
                        'data' => [
                            'dataTableId' => 'ipsDataTable',
                        ],
                    ],
                ],
            ]);
        }

        if ($action === 'delete') {
            $ip->logs()->delete();
            $ip->delete();

            return response()->json([
                'actions' => [
                    [
                        'type' => 'toastr',
                        'data' => [
                            'type' => 'success',
                            'title' => trans('security::responses.ip.deleted.title'),
                            'message' => trans('security::responses.ip.deleted.message'),
                        ],
                    ],
                    [
                        'type' => 'reloadDataTable',
                        'data' => [
                            'dataTableId' => 'ipsDataTable',
                        ],
                    ],
                ],
            ]);
        }

        throw new \Exception('Invalid action');
    }
}
