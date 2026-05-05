<?php
namespace HoltBosse\Alba\Migrations;

use HoltBosse\Alba\Core\{Migration, Message, MessageType};
use HoltBosse\DB\DB;
use Symfony\Component\Console\Output\OutputInterface;

class PageDraftDataMigration extends Migration {
    public function isNeeded(): Message {
        if($this->status == null) {
            $result = DB::fetchAll("show columns FROM `pages` LIKE 'draft_data'");
            if(!$result) {
                $this->status = new Message(false, MessageType::Warning, "Pages table missing draft_data column");
            } else {
                $this->status = new Message(true, MessageType::Success, "Pages table has draft_data column");
            }
        }

        return $this->status;
    }

    public function run(?OutputInterface $output=null): Message {
        if($this->isNeeded()->success) {
            return new Message(true, MessageType::Success, "Pages table OK.");
        } else {
            DB::exec("ALTER TABLE `pages` ADD `draft_data` LONGTEXT NULL DEFAULT NULL COMMENT 'Puck visual editor JSON draft';");
            return new Message(true, MessageType::Success, "Added draft_data column");
        }
    }
}
