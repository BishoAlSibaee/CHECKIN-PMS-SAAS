<?php

namespace App\Http\Controllers;

use App\Http\Requests\JobTitle\AddJobTitleRequest;
use App\Http\Requests\JobTitle\DeleteJobTitleRequest;
use App\Http\Requests\JobTitle\GetJobTitleByDepartmentRequest;
use App\Http\Requests\JobTitle\UpdateJobTitleRequest;
use App\Models\Job_title;
use App\Services\Cache\CacheInvalidator;
use App\Services\Cache\ReferenceDataCache;

class JobTitlesController extends Controller
{
    public function index()
    {
        try {
            $perPage = \returnPerPage();
            $page = max(1, (int) request()->input('page', 1));
            $jobTitles = ReferenceDataCache::remember(
                'job_titles',
                "page:{$page}:per:{$perPage}",
                fn () => Job_title::query()->paginate($perPage)
            );

            return \Pagination($jobTitles);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function store(AddJobTitleRequest $request)
    {
        try {
            $jobTitle = Job_title::create([
                'name_ar' => $request->name_ar,
                'name_en' => $request->name_en,
                'description' => $request->description ?? null,
                'department_id' => $request->department_id,
            ]);
            CacheInvalidator::jobTitlesChanged();

            return \SuccessData('Job title added successfully', $jobTitle);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function update(UpdateJobTitleRequest $request)
    {
        try {
            $jobTitle = Job_title::find($request->id);

            $jobTitle->update([
                'name_ar' => $request->name_ar ?? $jobTitle->name_ar,
                'name_en' => $request->name_en ?? $jobTitle->name_en,
                'description' => $request->description ?? $jobTitle->description,
                'department_id' => $request->department_id ?? $jobTitle->department_id,
            ]);
            CacheInvalidator::jobTitlesChanged();

            return \SuccessData('Job title updated successfully', $jobTitle);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function destroy(DeleteJobTitleRequest $request)
    {
        try {
            $jobTitle = Job_title::find($request->id);

            if ($jobTitle->users()->exists()) {
                return \Failed('Job title linked to users, can\'t delete.');
            }

            $jobTitle->delete();
            CacheInvalidator::jobTitlesChanged();

            return \Success('Job title deleted successfully');
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function getByDepartment(GetJobTitleByDepartmentRequest $request)
    {
        try {
            $departmentId = (int) $request->department_id;
            $jobTitles = ReferenceDataCache::remember(
                'job_titles',
                "department:{$departmentId}",
                fn () => Job_title::query()
                    ->where('department_id', $departmentId)
                    ->get()
            );

            return \SuccessData('Job titles retrieved successfully', $jobTitles);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }
}
