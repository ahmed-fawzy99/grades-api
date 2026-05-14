<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\V1\GradeRequest;
use App\Http\Resources\Api\V1\GradeResource;
use App\Services\GradeService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class GradeController extends ApiController
{
    public function __construct(protected GradeService $gradeService) {}

    /**
     * Get All Grades (Paginated, JSON:API).
     *
     *  Defaults to 25 items per page.
     *
     *  Pagination params: `?page[number]=x&page[size]=y`
     *
     *  Sparse fieldsets: `?fields[grades]=name,score`
     */
    public function index()
    {
        try {
            return GradeResource::collection($this->gradeService->list());
        } catch (HttpException $e) {
            throw $e;
        } catch (\Exception $e) {
            return $this->error('Grades Retrieval Error', null, 500);
        }
    }

    /**
     * Store a New Grade.
     */
    public function store(GradeRequest $request)
    {
        try {
            $grade = $this->gradeService->create($request->validated());

            return (new GradeResource($grade))
                ->response()
                ->setStatusCode(201);
        } catch (\Exception $e) {
            return $this->error('Grade Creation Error', null, 500);
        }
    }

    /**
     * Display the specified Grade.
     */
    public function show(string $id)
    {
        try {
            return new GradeResource($this->gradeService->find($id));
        } catch (ModelNotFoundException $e) {
            return $this->error('Grade not found', null, 404);
        } catch (\Exception $e) {
            return $this->error('Grade Retrieval Error', null, 500);
        }
    }

    /**
     * Delete a Grade.
     */
    public function destroy(string $id)
    {
        try {
            $this->gradeService->delete($id);

            return $this->ok('Grade Deleted Successfully');
        } catch (ModelNotFoundException $e) {
            return $this->error('Grade not found', null, 404);
        } catch (\Exception $e) {
            return $this->error('Grade Deletion Error', null, 500);
        }
    }
}
