<?php
namespace pistol88\service\models\cost;

use Yii;
use yii\base\Model;
use yii\data\ActiveDataProvider;
use pistol88\service\models\Cost;

class CostSearch extends Cost
{
    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['id', 'user_id', 'session_id'], 'integer'],
            [['sum', 'date', 'name'], 'safe'],
        ];
    }

    /**
     * @inheritdoc
     */
    public function scenarios()
    {
        // bypass scenarios() implementation in the parent class
        return Model::scenarios();
    }

    public function search($params)
    {
        $query = Cost::find();

        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'sort' => ['defaultOrder' => ['id' => SORT_DESC]],
        ]);

        if (!($this->load($params) && $this->validate())) {
            return $dataProvider;
        }

        $query->andFilterWhere([
            'id' => $this->id,
            'user_id' => $this->user_id,
            'sum' => $this->sum,
            'session_id' => $this->session_id,
        ]);

        if($dateStart = yii::$app->request->get('date_start')) {
            $dateStart = date('Y-m-d', strtotime($dateStart));
            if(!yii::$app->request->get('date_stop')) {
                $query->andWhere('DATE_FORMAT(date, "%Y-%m-%d") = :dateStart', [':dateStart' => $dateStart]);
            } else {
                $query->andWhere('date >= :dateStart', [':dateStart' => $dateStart]);
            }
        }
        
        if($dateStop = yii::$app->request->get('date_stop')) {
            $dateStop = date('Y-m-d', strtotime($dateStop));
            if($dateStop == '0000-00-00 00:00:00') {
                $dateStop = date('Y-m-d');
            }
        
            $query->andWhere('date <= :dateStop', [':dateStop' => $dateStop]);
        }

        $query->andFilterWhere(['like', 'name', $this->name]);
        $query->andFilterWhere(['like', 'date', $this->date]);

        return $dataProvider;
    }
}
