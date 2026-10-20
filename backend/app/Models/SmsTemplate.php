<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A saved message an admin can reuse when sending an SMS to passengers. */
#[Fillable(['name', 'body'])]
class SmsTemplate extends Model {}
