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
        $termsTable = config('nestedterms.terms_table', 'terms');
        $tagsTable = config('nestedterms.tags_table', 'tags');

        Schema::create($tagsTable, function (Blueprint $table) use ($termsTable, $tagsTable) {
            $table->id();

            // Term foreign key
            $table->foreignId('term_id')
                ->constrained($termsTable)
                ->onDelete('cascade');

            // For infinite nesting
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained($tagsTable)
                ->onDelete('cascade');

            // Slug unique within the same Term
            $table->string('slug');
            $table->unique(['term_id', 'slug']);

            // For dynamic casting
            $table->string('type')->nullable();
            $table->text('value')->nullable();

            $table->string('name');
            $table->text('description')->nullable();
            $table->json('meta')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index('parent_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists(config('nestedterms.tags_table', 'tags'));
    }
};
