<?php
namespace Biz\Sms\SmsProcessor;

use Topxia\Common\SmsToolkit;
use Topxia\Common\ArrayToolkit;
use Topxia\Common\StringToolkit;
use Biz\CloudPlatform\CloudAPIFactory;
use Symfony\Component\Security\Core\Encoder\MessageDigestPasswordEncoder;

class LiveOpenLessonSmsProcessor implements SmsProcessor
{
    protected $biz;

    public function __construct(Biz $biz)
    {
        $this->$biz = $biz;
    }

    public function getUrls($targetId, $smsType)
    {
        $lesson = $this->getOpenCourseService()->getLesson($targetId);
        $course = $this->getOpenCourseService()->getCourse($lesson['courseId']);
        $count  = 0;

        $count = $this->getOpenCourseService()->countMembers(array('courseId' => $course['id']));

        global $kernel;
        $container          = $kernel->getContainer();
        $siteSetting        = $this->getSettingService()->get('site');
        $siteSetting['url'] = rtrim($siteSetting['url']);
        $siteSetting['url'] = rtrim($siteSetting['url'], '/');
        $hostName           = $siteSetting['url'];
        $api                = CloudAPIFactory::create('root');

        for ($i = 0; $i <= intval($count / 1000); $i++) {
            $urls[$i] = $hostName;
            $urls[$i] .= $container->get('router')->generate('edu_cloud_sms_send_callback', array('targetType' => 'liveOpenLesson', 'targetId' => $targetId));
            $urls[$i] .= '?index='.($i * 1000);
            $urls[$i] .= '&smsType='.$smsType;
            $sign = $this->getSignEncoder()->encodePassword($urls[$i], $api->getAccessKey());
            $sign = rawurlencode($sign);
            $urls[$i] .= '&sign='.$sign;
        }

        return array('count' => $count, 'urls' => $urls);
    }

    public function getSmsInfo($targetId, $index, $smsType)
    {
        global $kernel;
        $siteSetting        = $this->getSettingService()->get('site');
        $siteSetting['url'] = rtrim($siteSetting['url']);
        $siteSetting['url'] = rtrim($siteSetting['url'], '/');
        $hostName           = $siteSetting['url'];
        $lesson             = $this->getOpenCourseService()->getLesson($targetId);

        if (empty($lesson)) {
            throw new \RuntimeException('课时不存在');
        }

        $originUrl = $hostName;
        $originUrl .= $kernel->getContainer()->get('router')->generate('open_course_show', array('courseId' => $lesson['courseId']));

        $shortUrl = SmsToolkit::getShortLink($originUrl);
        $url      = empty($shortUrl) ? $originUrl : $shortUrl;
        $course   = $this->getOpenCourseService()->getCourse($lesson['courseId']);
        $to       = '';

        $students = $this->getOpenCourseService()->searchMembers(array('courseId' => $course['id']), array('createdTime', 'Desc'), $index, 1000);

        $to = array_filter(ArrayToolkit::column($students, 'mobile'));
        $to = implode(',', $to);

        $parameters['lesson_title'] = '';

        if ($lesson['type'] == 'liveOpen') {
            $parameters['startTime'] = date("Y-m-d H:i:s", $lesson['startTime']);
        }

        $course['title']            = StringToolkit::cutter($course['title'], 20, 15, 4);
        $parameters['course_title'] = '直播公开课：《'.$course['title'].'》';

        $description = $parameters['course_title'].' '.$parameters['lesson_title'].'预告';

        $parameters['url'] = $url.' ';

        return array('mobile' => $to, 'category' => $smsType, 'sendStyle' => 'templateId', 'description' => $description, 'parameters' => $parameters);
    }

    protected function getUserService()
    {
        return $this->biz->service('User:UserService');
    }

    protected function getSettingService()
    {
        return $this->biz->service('System:SettingService');
    }

    protected function getOpenCourseService()
    {
        return $this->biz->service('OpenCourse:OpenCourseService');
    }

    protected function getSignEncoder()
    {
        return new MessageDigestPasswordEncoder('sha256');
    }
}
