<?php

namespace Spatie\Newsletter;

use DrewM\MailChimp\MailChimp;

class Newsletter
{
    /** @var \DrewM\MailChimp\MailChimp */
    protected $mailChimp;

    /** @var \Spatie\Newsletter\NewsletterListCollection */
    protected $lists;

    const TIMEOUT = 10;

    public function __construct(MailChimp $mailChimp, NewsletterListCollection $lists)
    {
        $this->mailChimp = $mailChimp;

        $this->lists = $lists;
    }

    public function subscribe(string $email, array $mergeFields = [], string $listName = '', array $options = [], $timeout = self::TIMEOUT)
    {
        $list = $this->lists->findByName($listName);

        $options = $this->getSubscriptionOptions($email, $mergeFields, $options);

        $response = $this->mailChimp->post("lists/{$list->getId()}/members", $options, $timeout);

        if (! $this->lastActionSucceeded()) {
            return false;
        }

        return $response;
    }

    public function subscribePending(string $email, array $mergeFields = [], string $listName = '', array $options = [], $timeout = self::TIMEOUT)
    {
        $options = array_merge($options, ['status' => 'pending']);

        return $this->subscribe($email, $mergeFields, $listName, $options);
    }

    public function subscribeOrUpdate(string $email, array $mergeFields = [], string $listName = '', array $options = [], $timeout = self::TIMEOUT)
    {
        $list = $this->lists->findByName($listName);

        $options = $this->getSubscriptionOptions($email, $mergeFields, $options);

        $response = $this->mailChimp->put("lists/{$list->getId()}/members/{$this->getSubscriberHash($email)}", $options, $timeout);

        if (! $this->lastActionSucceeded()) {
            return false;
        }

        return $response;
    }

    public function getMembers(string $listName = '', array $parameters = [], $timeout = self::TIMEOUT)
    {
        $list = $this->lists->findByName($listName);

        return $this->mailChimp->get("lists/{$list->getId()}/members", $parameters, $timeout);
    }

    public function getMember(string $email, string $listName = '', $timeout = self::TIMEOUT)
    {
        $list = $this->lists->findByName($listName);

        return $this->mailChimp->get("lists/{$list->getId()}/members/{$this->getSubscriberHash($email)}", [], $timeout);
    }

    public function getMemberActivity(string $email, string $listName = '', $timeout = self::TIMEOUT)
    {
        $list = $this->lists->findByName($listName);

        return $this->mailChimp->get("lists/{$list->getId()}/members/{$this->getSubscriberHash($email)}/activity", [], $timeout);
    }

    public function hasMember(string $email, string $listName = '', $timeout = self::TIMEOUT): bool
    {
        $response = $this->getMember($email, $listName, $timeout);

        if (! isset($response['email_address'])) {
            return false;
        }

        if (strtolower($response['email_address']) != strtolower($email)) {
            return false;
        }

        return true;
    }

    public function isSubscribed(string $email, string $listName = '', $timeout = self::TIMEOUT): bool
    {
        $response = $this->getMember($email, $listName, $timeout);

        if (! $this->lastActionSucceeded()) {
            return false;
        }

        if ($response['status'] != 'subscribed') {
            return false;
        }

        return true;
    }

    public function unsubscribe(string $email, string $listName = '', $timeout = self::TIMEOUT)
    {
        $list = $this->lists->findByName($listName);

        $response = $this->mailChimp->patch("lists/{$list->getId()}/members/{$this->getSubscriberHash($email)}", [
            'status' => 'unsubscribed',
        ], $timeout);

        if (! $this->lastActionSucceeded()) {
            return false;
        }

        return $response;
    }

    public function updateEmailAddress(string $currentEmailAddress, string $newEmailAddress, string $listName = '', $timeout = self::TIMEOUT)
    {
        $list = $this->lists->findByName($listName);

        $response = $this->mailChimp->patch("lists/{$list->getId()}/members/{$this->getSubscriberHash($currentEmailAddress)}", [
            'email_address' => $newEmailAddress,
        ], $timeout);

        return $response;
    }

    public function delete(string $email, string $listName = '', $timeout = self::TIMEOUT)
    {
        $list = $this->lists->findByName($listName);

        $response = $this->mailChimp->delete("lists/{$list->getId()}/members/{$this->getSubscriberHash($email)}", [], $timeout);

        return $response;
    }

    public function deletePermanently(string $email, string $listName = '', $timeout = self::TIMEOUT)
    {
        $list = $this->lists->findByName($listName);

        $response = $this->mailChimp->post("lists/{$list->getId()}/members/{$this->getSubscriberHash($email)}/actions/delete-permanent", [], $timeout);

        return $response;
    }

    public function getTags(string $email, string $listName = '', $timeout = self::TIMEOUT)
    {
        $list = $this->lists->findByName($listName);

        return $this->mailChimp->get("lists/{$list->getId()}/members/{$this->getSubscriberHash($email)}/tags", [], $timeout);
    }

    public function addTags(array $tags, string $email, string $listName = '', $timeout = self::TIMEOUT)
    {
        $list = $this->lists->findByName($listName);

        $payload = collect($tags)->map(function ($tag) {
            return ['name' => $tag, 'status' => 'active'];
        })->toArray();

        return $this->mailChimp->post("lists/{$list->getId()}/members/{$this->getSubscriberHash($email)}/tags", [
            'tags' => $payload,
        ], $timeout);
    }

    public function removeTags(array $tags, string $email, string $listName = '', $timeout = self::TIMEOUT)
    {
        $list = $this->lists->findByName($listName);

        $payload = collect($tags)->map(function ($tag) {
            return ['name' => $tag, 'status' => 'inactive'];
        })->toArray();

        return $this->mailChimp->post("lists/{$list->getId()}/members/{$this->getSubscriberHash($email)}/tags", [
            'tags' => $payload,
        ], $timeout);
    }

    public function createCampaign(
        string $fromName,
        string $replyTo,
        string $subject,
        string $html = '',
        string $listName = '',
        array $options = [],
        array $contentOptions = [],
        $timeout = self::TIMEOUT
    )
    {
        $list = $this->lists->findByName($listName);

        $defaultOptions = [
            'type' => 'regular',
            'recipients' => [
                'list_id' => $list->getId(),
            ],
            'settings' => [
                'subject_line' => $subject,
                'from_name' => $fromName,
                'reply_to' => $replyTo,
            ],
        ];

        $options = array_merge($defaultOptions, $options);

        $response = $this->mailChimp->post('campaigns', $options, $timeout);

        if (! $this->lastActionSucceeded()) {
            return false;
        }

        if ($html === '') {
            return $response;
        }

        if (! $this->updateContent($response['id'], $html, $contentOptions, $timeout)) {
            return false;
        }

        return $response;
    }

    public function updateContent(string $campaignId, string $html, array $options = [], $timeout = self::TIMEOUT)
    {
        $defaultOptions = compact('html');

        $options = array_merge($defaultOptions, $options);

        $response = $this->mailChimp->put("campaigns/{$campaignId}/content", $options, $timeout);

        if (! $this->lastActionSucceeded()) {
            return false;
        }

        return $response;
    }

    public function getApi(): MailChimp
    {
        return $this->mailChimp;
    }

    /**
     * @return string|false
     */
    public function getLastError()
    {
        return $this->mailChimp->getLastError();
    }

    public function lastActionSucceeded(): bool
    {
        return $this->mailChimp->success();
    }

    protected function getSubscriberHash(string $email): string
    {
        return $this->mailChimp->subscriberHash($email);
    }

    protected function getSubscriptionOptions(string $email, array $mergeFields, array $options): array
    {
        $defaultOptions = [
            'email_address' => $email,
            'status' => 'subscribed',
            'email_type' => 'html',
        ];

        if (count($mergeFields)) {
            $defaultOptions['merge_fields'] = $mergeFields;
        }

        $options = array_merge($defaultOptions, $options);

        return $options;
    }
}
