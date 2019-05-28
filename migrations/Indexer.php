<?php
namespace x51\yii2\modules\indexer\migrations;
	use yii\db\Migration;

class Indexer extends Migration {
    public $baseTableName = 'indexer';
	
	public function init() {
		parent::init();
	} // end init
	
	/**
     * {@inheritdoc}
     */
    public function safeUp()
    {
		$tableOptions = null;
        if ($this->db->driverName === 'mysql') {
            // http://stackoverflow.com/questions/766809/whats-the-difference-between-utf8-general-ci-and-utf8-unicode-ci
            $tableOptions = 'CHARACTER SET utf8 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';
        }
		
		// создаем таблицы
		$tblPosts = [
            'id' => $this->primaryKey(),
			'url' => $this->string(250)->notNull()->defaultValue(''),
			'title' => $this->string(250)->notNull()->defaultValue(''),
			'content' => $this->text(),
			'orig_content' => $this->text(),
			'snippet' => $this->text(),
			'attrs' => $this->string(250)->notNull()->defaultValue(''),
			'role' => $this->string(250)->notNull()->defaultValue(''),
			'change_date' => $this->timestamp(),
			'ttl' => $this->timestamp()->defaultValue(null),
        ];
		$this->createTable('{{%'.$this->baseTableName.'}}', $tblPosts, $tableOptions);
			$this->createIndex('k_'.$this->baseTableName.'_url', '{{%'.$this->baseTableName.'}}', 'url', true);
			$this->createIndex('k_'.$this->baseTableName.'_role', '{{%'.$this->baseTableName.'}}', 'role', true);			
			$this->execute('ALTER TABLE {{%'.$this->baseTableName.'}} ADD FULLTEXT `content` (`title`, `content`);');
	} // end safeUp

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
		$this->dropTable('{{%'.$this->baseTableName.'}}');		
    }
} // end class