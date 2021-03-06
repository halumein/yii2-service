<?php
namespace pistol88\service\controllers;

use yii;
use pistol88\service\events\Earnings;
use pistol88\service\models\Cost;
use pistol88\service\models\Payment;
use pistol88\staffer\models\Fine;
use pistol88\order\models\Order;
use pistol88\order\models\PaymentType;
use pistol88\worksess\models\Session;
use yii\db\Query;
use yii\helpers\Html;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use yii\filters\VerbFilter;
use yii\filters\AccessControl;

class ReportController extends Controller
{
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::className(),
				'only' => ['index', 'get-sessions'],
                'rules' => [
                    [
                        'allow' => true,
                        'roles' => $this->module->adminRoles,
                    ]
                ]
            ],
        ];
    }
    
    public function actionIndex($sessionId = null)
    {
        if(!$sessionId) {
            $session = yii::$app->worksess->soon();
        } else {
            $session = Session::findOne($sessionId);
        }
        
        $stat = null;

        $workerStat = [];

        $workers = [];
        
        $paymentsInfo = [];
        
        $paymentTypes = PaymentType::find()->all();
        
        $shopStat = [];
        
        $sessionId = 0;
        
        $costs = [];
        
        if($session) {
            $costs = Cost::findAll(['session_id' => $session->id]);
            
            $sessionId = $session->id;
            
            $workers = $session->users;
            
            $shopStat = yii::$app->order->getStatByModelAndDatePeriod('pistol88\shop\models\Product', $session->start, $session->stop);
            $stat = yii::$app->order->getStatByModelAndDatePeriod(['pistol88\service\models\CustomService', 'pistol88\service\models\Price'], $session->start, $session->stop);

            $shopStatPromocode = yii::$app->order->getStatByModelAndDatePeriod('pistol88\shop\models\Product', $session->start, $session->stop, "`promocode` != ''");
            $statPromocode = yii::$app->order->getStatByModelAndDatePeriod(['pistol88\service\models\CustomService', 'pistol88\service\models\Price'], $session->start, $session->stop, "`promocode` != ''");

            $workersCount = 0;
            
            foreach($workers as $worker) {
                if($worker->pay_type == 'base' | empty($worker->pay_type)) {
                    $workersCount++;
                }
            }

            foreach($workers as $worker) {
                if(empty($worker->persent)) {
                    $basePersent = $this->module->getWorkerPersent($session);
                } else {
                    $basePersent = $worker->persent;
                    
                }
                
                if($basePersent > 9) {
                    $persent = '0.'.$basePersent;
                } else {
                    $persent = '0.0'.$basePersent;
                }

                if(!isset($workerStat[$worker->id]['service_count'])) {
                    $workerStat[$worker->id]['service_count'] = 0; //Выполнено услуг
                    $workerStat[$worker->id]['order_count'] = 0; //Кол-во заказов
                    $workerStat[$worker->id]['service_total'] = 0; //Общая сумма выручки
                    $workerStat[$worker->id]['earnings'] = (int)$worker->fix; //
                    $workerStat[$worker->id]['fines'] = 0; //штрафы
                    $workerStat[$worker->id]['payment'] = Payment::findOne(['session_id' => $session->id, 'worker_id' => $worker->id]);
                    $workerStat[$worker->id]['bonus'] = 0;
                    $workerStat[$worker->id]['fine'] = 0;
                    $workerStat[$worker->id]['persent'] = $basePersent;
                }

                $workerSessions = $worker->getSessionsBySessions($session);

                $workerStat[$worker->id]['sessions'] = $workerSessions;

                foreach($workerSessions as $workSession) {
                    $userStat = yii::$app->order->getStatByModelAndDatePeriod(['pistol88\service\models\CustomService', 'pistol88\service\models\Price'], $workSession->start, $workSession->stop);
                    $workerStat[$worker->id]['service_count'] += $stat['count_elements'];
                    $workerStat[$worker->id]['order_count'] += $stat['count_order'];
                    $workerStat[$worker->id]['service_total'] += $stat['total'];

                    if($workersCount && !$worker->persent) {
                        $earning = ($stat['total']*$persent)/$workersCount;
                    } else {
                        $earning = ($stat['total']*$persent);
                    }

                    $earningsEvent = new Earnings(
                        [
                            'worker' => $worker,
                            'persent' => $persent,
                            'total' => $stat['total'],
                            'userTotal' => $userStat['total'],
                            'workersCount' => $workersCount,
                            'earning' => $earning,
                        ]
                    );
                    
                    $module = $this->module;
                    $module->trigger($module::EVENT_EARNINGS, $earningsEvent);
                    
                    $earning = $earningsEvent->earning;
                    
                    $fines = $worker->getFinesByDatePeriod($workSession->start, $workSession->stop)->sum('sum');
                    
                    $workerStat[$worker->id]['fines'] += $fines;
                    
                    $workerStat[$worker->id]['earnings'] += $earning;
                    $workerStat[$worker->id]['earnings'] -= $fines;
                    
                    if($earningsEvent->bonus) {
                        $workerStat[$worker->id]['bonus'] = $earningsEvent->bonus;
                    }
                    
                    if($earningsEvent->fine) {
                        $workerStat[$worker->id]['fine'] = $earningsEvent->fine;
                    }
                }
            }
            
            $stop = $session->stop;
            if(!$stop) {
                $stop = date('Y-m-d H:i:s');
            }
            
            foreach($paymentTypes as $pt) {
                $query = new Query();
                $sum = $query->from([Order::tableName()])
                        ->where('date >= :dateStart', [':dateStart' => $session->start])
                        ->andWhere('date <= :dateStop', [':dateStop' => $stop])
                        ->andWhere(['payment_type_id' => $pt->id])
                        ->sum('cost');

                $paymentsInfo[$pt->name] = (int)$sum;
            }
        }

        $workerPersent = $this->module->workerPersent;
        
        if($session) {
            $date = date('Y-m-d', $session->start_timestamp);
        } else {
            $date = date('Y-m-d');
        }
        
        $sessions = yii::$app->worksess->getSessions(null, $date);

        return $this->render('index', [
            'shopStat' => $shopStat,
            'date' => $date,
            'costs' => $costs,
            'session' => $session,
            'sessions' => $sessions,
            'sessionId' => $sessionId,
            'stat' => $stat,
            'workerPersent' => $workerPersent,
            'paymentTypes' => $paymentTypes,
            'paymentsInfo' => $paymentsInfo,
            'workers' => $workers,
            'workerStat' => $workerStat,
            'module' => $this->module,
        ]);
    }
    
    public function actionGetSessions()
    {
        $date = date('Y-m-d', strtotime(yii::$app->request->post('date')));
        
        $session = yii::$app->worksess->getSessions(null, $date);

        $json = [];

        if(empty($session)) {
            $json['HtmlList'] = '<ul><li>Сессии не были открыты.</li></ul>';
        } else {
            $json['HtmlList'] = Html::ul($session, ['item' => function($item, $index) {
                return html::tag('li', Html::a(date('d.m.Y H:i:s', $item->start_timestamp) . ' ' . $item->shiftName . ' ('.$item->user->name.')', ['/service/report/index', 'sessionId' => $item->id]));
            }]);
        }

        die(json_encode($json));
    }
}
