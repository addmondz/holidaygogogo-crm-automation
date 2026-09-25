<?php

use App\Http\Controllers\Admin\AgentController;
use App\Http\Controllers\Admin\ChannelController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SimulatorController;
use App\Http\Controllers\Admin\TagController;
use App\Http\Controllers\AvailabilityController;
use App\Http\Controllers\Contacts\ContactController;
use App\Http\Controllers\Contacts\NoteController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Inbox\ConversationController;
use App\Http\Controllers\Inbox\InboxController;
use App\Http\Controllers\Inbox\MessageController;
use App\Http\Controllers\Inbox\TemplateMessageController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\QuickReplyController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'inbox.index' : 'login'))->name('home');

Route::middleware('auth')->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::patch('availability', AvailabilityController::class)->name('availability.update');

    // Inbox
    Route::get('inbox', [InboxController::class, 'index'])->name('inbox.index');
    Route::get('inbox/{conversation}', [InboxController::class, 'show'])->name('inbox.show');
    Route::get('conversations/{conversation}/messages', [MessageController::class, 'index'])->name('conversations.messages.index');
    Route::post('conversations/{conversation}/messages', [MessageController::class, 'store'])->name('conversations.messages.store');
    Route::post('conversations/{conversation}/template', [TemplateMessageController::class, 'store'])->name('conversations.template.store');
    Route::patch('conversations/{conversation}/assignee', [ConversationController::class, 'assign'])->name('conversations.assign');
    Route::patch('conversations/{conversation}/status', [ConversationController::class, 'status'])->name('conversations.status');
    Route::get('media/{message}', MediaController::class)->name('media.show');

    // Contacts (leads)
    Route::get('contacts', [ContactController::class, 'index'])->name('contacts.index');
    Route::post('contacts', [ContactController::class, 'store'])->name('contacts.store');
    Route::patch('contacts/{contact}', [ContactController::class, 'update'])->name('contacts.update');
    Route::delete('contacts/{contact}', [ContactController::class, 'destroy'])->name('contacts.destroy');
    Route::put('contacts/{contact}/tags', [ContactController::class, 'tags'])->name('contacts.tags');
    Route::post('contacts/{contact}/whatsapp', [ContactController::class, 'startWhatsApp'])->name('contacts.whatsapp');
    Route::post('contacts/{contact}/notes', [NoteController::class, 'store'])->name('contacts.notes.store');
    Route::delete('notes/{note}', [NoteController::class, 'destroy'])->name('notes.destroy');

    // Quick replies
    Route::get('quick-replies', [QuickReplyController::class, 'index'])->name('quick-replies.index');
    Route::post('quick-replies', [QuickReplyController::class, 'store'])->name('quick-replies.store');
    Route::post('quick-replies/{quickReply}', [QuickReplyController::class, 'update'])->name('quick-replies.update');
    Route::delete('quick-replies/{quickReply}', [QuickReplyController::class, 'destroy'])->name('quick-replies.destroy');
    Route::get('quick-replies/{quickReply}/attachment', [QuickReplyController::class, 'attachment'])->name('quick-replies.attachment');

    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('agents', [AgentController::class, 'index'])->name('agents.index');
        Route::post('agents', [AgentController::class, 'store'])->name('agents.store');
        Route::patch('agents/{agent}', [AgentController::class, 'update'])->name('agents.update');
        Route::delete('agents/{agent}', [AgentController::class, 'destroy'])->name('agents.destroy');

        Route::get('tags', [TagController::class, 'index'])->name('tags.index');
        Route::post('tags', [TagController::class, 'store'])->name('tags.store');
        Route::patch('tags/{tag}', [TagController::class, 'update'])->name('tags.update');
        Route::delete('tags/{tag}', [TagController::class, 'destroy'])->name('tags.destroy');

        Route::get('channels', [ChannelController::class, 'index'])->name('channels.index');
        Route::post('channels', [ChannelController::class, 'store'])->name('channels.store');
        Route::patch('channels/{channel}', [ChannelController::class, 'update'])->name('channels.update');
        Route::delete('channels/{channel}', [ChannelController::class, 'destroy'])->name('channels.destroy');
        Route::post('channels/{channel}/test', [ChannelController::class, 'test'])->name('channels.test');
        Route::post('channels/{channel}/sync-templates', [ChannelController::class, 'syncTemplates'])->name('channels.sync-templates');

        Route::post('simulate', [SimulatorController::class, 'store'])->name('simulate');

        Route::get('settings', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::patch('settings', [SettingsController::class, 'update'])->name('settings.update');
    });
});

require __DIR__.'/settings.php';
