<?php

namespace App\AiOffice\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectFile extends Model
{
    protected $table = 'ai_office_project_files';

    protected $fillable = ['project_id', 'path', 'size_bytes', 'checksum', 'last_modified_by_agent_id'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function lastModifiedBy(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'last_modified_by_agent_id');
    }
}
