<?php

namespace App\Tests\Security;

use App\Entity\MaintenanceRecord;
use App\Entity\User;
use App\Security\Voter\MaintenanceRecordVoter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class MaintenanceRecordVoterTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, bool, int}>
     */
    public static function cases(): iterable
    {
        // [attribute, role of the viewer, viewer created the record?, expected vote]
        foreach ([MaintenanceRecordVoter::VIEW, MaintenanceRecordVoter::EDIT, MaintenanceRecordVoter::DELETE] as $attribute) {
            yield "admin $attribute someone else's" => [$attribute, User::ROLE_ADMIN, false, VoterInterface::ACCESS_GRANTED];
            yield "staff $attribute own" => [$attribute, User::ROLE_STAFF, true, VoterInterface::ACCESS_GRANTED];
            yield "customer $attribute" => [$attribute, User::ROLE_CUSTOMER, true, VoterInterface::ACCESS_DENIED];
        }

        yield "staff view someone else's" => [MaintenanceRecordVoter::VIEW, User::ROLE_STAFF, false, VoterInterface::ACCESS_GRANTED];
        yield "staff edit someone else's" => [MaintenanceRecordVoter::EDIT, User::ROLE_STAFF, false, VoterInterface::ACCESS_DENIED];
        yield "staff delete someone else's" => [MaintenanceRecordVoter::DELETE, User::ROLE_STAFF, false, VoterInterface::ACCESS_DENIED];
    }

    #[DataProvider('cases')]
    public function testVote(string $attribute, string $role, bool $ownsRecord, int $expected): void
    {
        $viewer = $this->user(1, $role);
        $author = $ownsRecord ? $viewer : $this->user(2, User::ROLE_STAFF);
        $record = (new MaintenanceRecord())->setCreatedBy($author);

        self::assertSame($expected, (new MaintenanceRecordVoter())->vote($this->token($viewer), $record, [$attribute]));
    }

    public function testInactiveStaffAreDeniedEvenOnOwnRecords(): void
    {
        $staff = $this->user(1, User::ROLE_STAFF)->setStatus(User::STATUS_INACTIVE);
        $record = (new MaintenanceRecord())->setCreatedBy($staff);

        self::assertSame(VoterInterface::ACCESS_DENIED, (new MaintenanceRecordVoter())->vote($this->token($staff), $record, [MaintenanceRecordVoter::EDIT]));
    }

    public function testAbstainsOnOtherAttributesAndSubjects(): void
    {
        $voter = new MaintenanceRecordVoter();
        $token = $this->token($this->user(1, User::ROLE_STAFF));

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new MaintenanceRecord(), ['ROLE_ADMIN']));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new \stdClass(), [MaintenanceRecordVoter::EDIT]));
    }

    private function user(int $id, string $role): User
    {
        $user = (new User())->setEmail("user$id@test.vj")->setRole($role);
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    private function token(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }
}
