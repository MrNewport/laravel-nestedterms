<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        $tagsTable     = config('nestedterms.tags_table', 'tags');
        $modelTagTable = config('nestedterms.model_tag_table', 'model_tag');

        Schema::create($modelTagTable, function (Blueprint $table) use ($tagsTable) {
            $table->id();

            $table->foreignId('tag_id')
                ->constrained($tagsTable)
                ->onDelete('cascade');

            // Polymorphic columns: model_type & model_id
            $table->morphs('model');

            $table->timestamps();

            // prevent duplicates
            $table->unique(['tag_id', 'model_id', 'model_type']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists(config('nestedterms.model_tag_table', 'model_tag'));
    }
};
