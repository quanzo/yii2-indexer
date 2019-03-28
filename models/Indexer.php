<?php

namespace x51\yii2\modules\indexer\models;

use Yii;

/**
 * This is the model class for table "{{%indexer}}".
 *
 * @property int $id
 * @property string $url
 * @property string $content
 * @property string $orig_content
 * @property string $change_date
 * @property string $ttl
 */
class Indexer extends \yii\db\ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return '{{%indexer}}';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['content', 'title'], 'required'],
            [['content', 'orig_content', 'title', 'orig_title'], 'string'],
            [['change_date', 'ttl'], 'safe'],
            [['url'], 'string', 'max' => 250],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => Yii::t('modules/indexer', 'ID'),
            'url' => Yii::t('modules/indexer', 'Url'),
            'title' => Yii::t('modules/indexer', 'Title'),
            'orig_title' => Yii::t('modules/indexer', 'Title (orig)'),
            'content' => Yii::t('modules/indexer', 'Content'),
            'orig_content' => Yii::t('modules/indexer', 'Content (orig)'),
            'change_date' => Yii::t('modules/indexer', 'Change Date'),
            'ttl' => Yii::t('modules/indexer', 'Ttl'),
        ];
    }

    /**
     * {@inheritdoc}
     * @return IndexerQuery the active query used by this AR class.
     */
    public static function find()
    {
        return new IndexerQuery(get_called_class());
    }
}
