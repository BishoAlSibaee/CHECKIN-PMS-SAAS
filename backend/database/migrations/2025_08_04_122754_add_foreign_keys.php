<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('floors', function (Blueprint $table) {
            $table->foreign('building_id')->references('id')->on('buildings');
        });
        Schema::table('suites', function (Blueprint $table) {
            $table->foreign('building_id')->references('id')->on('buildings');
            $table->foreign('floor_id')->references('id')->on('floors');
        });
        Schema::table('rooms', function (Blueprint $table) {
            $table->foreign('building_id')->references('id')->on('buildings');
            $table->foreign('floor_id')->references('id')->on('floors');
            $table->foreign('suite_id')->references('id')->on('suites');
            $table->foreign('room_type_id')->references('id')->on('room_types');
        });
        Schema::table('rooms_features', function (Blueprint $table) {
            $table->foreign('room_id')->references('id')->on('rooms');
            $table->foreign('feature_id')->references('id')->on('features');
        });
        Schema::table('reservation_penalties', function (Blueprint $table) {
            $table->foreign('reservation_id')->references('id')->on('reservations');
            $table->foreign('penalty_id')->references('id')->on('penalties');
        });
        Schema::table('reservation_taxes', function (Blueprint $table) {
            $table->foreign('reservation_id')->references('id')->on('reservations');
            $table->foreign('tax_id')->references('id')->on('taxes');
        });
        Schema::table('guest_classifications', function (Blueprint $table) {
            $table->foreign('discount_id')->references('id')->on('discounts');
        });
        Schema::table('jobtitles', function (Blueprint $table) {
            $table->foreign('department_id')->references('id')->on('departments');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('jobtitle_id')->references('id')->on('jobtitles');
            $table->foreign('department_id')->references('id')->on('departments');
            $table->foreign('discount_id')->references('id')->on('discounts');
        });
        if (Schema::hasTable('user_permissions')) {
            Schema::table('user_permissions', function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->foreign('permission_id')->references('id')->on('permissions')->onDelete('cascade');
            });
        }
        Schema::table('guest_classification_features', function (Blueprint $table) {
            $table->foreign('guest_classification_id')->references('id')->on('guest_classifications')->onDelete('cascade');
            $table->foreign('guest_feature_id')->references('id')->on('guests_features')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('floors', function (Blueprint $table) {
            $table->dropForeign(['building_id']);
        });
        Schema::table('suites', function (Blueprint $table) {
            $table->dropForeign(['building_id']);
            $table->dropForeign(['floor_id']);
        });
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropForeign(['building_id']);
            $table->dropForeign(['floor_id']);
            $table->dropForeign(['suite_id']);
            $table->dropForeign(['room_type_id']);
        });
        Schema::table('rooms_features', function (Blueprint $table) {
            $table->dropForeign(['room_id']);
            $table->dropForeign(['feature_id']);
        });
        Schema::table('reservation_penalties', function (Blueprint $table) {
            $table->dropForeign(['reservation_id']);
            $table->dropForeign(['penalty_id']);
        });
        Schema::table('reservation_taxes', function (Blueprint $table) {
            $table->dropForeign(['reservation_id']);
            $table->dropForeign(['tax_id']);
        });
        Schema::table('guest_classifications', function (Blueprint $table) {
            $table->dropForeign(['discount_id']);
        });
        Schema::table('jobtitles', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['jobtitle_id']);
            $table->dropForeign(['department_id']);
            $table->dropForeign(['discount_id']);
        });
        if (Schema::hasTable('user_permissions')) {
            Schema::table('user_permissions', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
                $table->dropForeign(['permission_id']);
            });
        }
        Schema::table('guest_classification_features', function (Blueprint $table) {
            $table->dropForeign(['guest_classification_id']);
            $table->dropForeign(['guest_feature_id']);
        });
    }
};
