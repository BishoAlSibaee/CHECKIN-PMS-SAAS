<?php

namespace App\Http\Controllers;

use App\Http\Requests\Department\AddDepartmentRequest;
use App\Http\Requests\Department\DeleteDepartmentRequest;
use App\Http\Requests\Department\ShowDepartmentRequest;
use App\Http\Requests\Department\UpdateDepartmentRequest;
use App\Models\Department;
use App\Services\Cache\CacheInvalidator;
use App\Services\Cache\ReferenceDataCache;

class DepartmentsController extends Controller
{
    public function index()
    {
        try {
            $perPage = \returnPerPage();
            $page = max(1, (int) request()->input('page', 1));
            $departments = ReferenceDataCache::remember(
                'departments',
                "page:{$page}:per:{$perPage}",
                fn () => Department::query()->paginate($perPage)
            );

            return \Pagination($departments);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function store(AddDepartmentRequest $request)
    {
        try {
            $department = Department::create([
                'name_ar' => $request->name_ar,
                'name_en' => $request->name_en,
                'description' => $request->description ?? null,
            ]);
            CacheInvalidator::departmentsChanged();

            return \SuccessData('Department added successfully', $department);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function show(ShowDepartmentRequest $request)
    {
        try {
            $department = Department::find($request->id);

            return \SuccessData('Department retrieved successfully', $department);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function update(UpdateDepartmentRequest $request)
    {
        try {
            $department = Department::find($request->id);

            $department->update([
                'name_ar' => $request->name_ar ?? $department->name_ar,
                'name_en' => $request->name_en ?? $department->name_en,
                'description' => $request->description ?? $department->description,
            ]);
            CacheInvalidator::departmentsChanged();

            return \SuccessData('Department updated successfully', $department);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function destroy(DeleteDepartmentRequest $request)
    {
        try {
            $department = Department::find($request->id);

            if ($department->jobtitles()->exists()) {
                return \Failed('Department linked to job titles, can\'t delete.');
            }

            $department->delete();
            CacheInvalidator::departmentsChanged();

            return \Success('Department deleted successfully');
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }
}
