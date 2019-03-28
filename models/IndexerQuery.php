<?php

namespace x51\yii2\modules\indexer\models;

/**
 * This is the ActiveQuery class for [[Indexer]].
 *
 * @see Indexer
 */
class IndexerQuery extends \yii\db\ActiveQuery
{
    /*public function active()
    {
        return $this->andWhere('[[status]]=1');
    }*/

    /**
     * {@inheritdoc}
     * @return Indexer[]|array
     */
    public function all($db = null)
    {
        return parent::all($db);
    }

    /**
     * {@inheritdoc}
     * @return Indexer|array|null
     */
    public function one($db = null)
    {
        return parent::one($db);
    }
}
