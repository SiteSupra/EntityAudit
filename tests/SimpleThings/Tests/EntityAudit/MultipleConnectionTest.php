<?php
/*
 * (c) 2011 SimpleThings GmbH
 *
 * @package SimpleThings\EntityAudit
 * @author Benjamin Eberlei <eberlei@simplethings.de>
 * @link http://www.simplethings.de
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA
 */

namespace SimpleThings\EntityAudit\Tests;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Driver\PDOSqlite\Driver;
use Doctrine\ORM\EntityManager;
use SimpleThings\EntityAudit\AuditConfiguration;
use SimpleThings\EntityAudit\AuditManager;
use Doctrine\ORM\Mapping as ORM;

class MultipleConnectionTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var EntityManager
     */
    protected $em = null;

    /**
     * @var AuditManager
     */
    protected $auditManager = null;

    protected $schemaEntities = array('SimpleThings\EntityAudit\Tests\MultipleConnectionEntity');

    protected $auditedEntities = array('SimpleThings\EntityAudit\Tests\MultipleConnectionEntity');

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    protected $mainConnectionInstance;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    protected $auditConnectionInstance;

    public function setUp()
    {
        $reader = new \Doctrine\Common\Annotations\AnnotationReader();
        $driver = new \Doctrine\ORM\Mapping\Driver\AnnotationDriver($reader);
        $config = new \Doctrine\ORM\Configuration();
        $config->setMetadataCacheImpl(new \Doctrine\Common\Cache\ArrayCache);
        $config->setQueryCacheImpl(new \Doctrine\Common\Cache\ArrayCache);
        $config->setProxyDir(sys_get_temp_dir());
        $config->setProxyNamespace('SimpleThings\EntityAudit\Tests\Proxies');
        $config->setMetadataDriverImpl($driver);

        $evm = new EventManager();

        $this->mainConnectionInstance = $mainConnectionInstance = $this->getMockBuilder('Doctrine\DBAL\Connection')
            ->setConstructorArgs(array(array('memory' => true), new Driver(), $config, $evm))
            ->enableProxyingToOriginalMethods()
            ->getMock();

        $this->auditConnectionInstance = $auditConnectionInstance = $this->getMockBuilder('Doctrine\DBAL\Connection')
            ->setConstructorArgs(array(array('memory' => true), new Driver(), $config, new EventManager()))
            ->enableProxyingToOriginalMethods()
            ->getMock();

        $auditConfig = new AuditConfiguration();
        $auditConfig->setConnection($auditConnectionInstance);
        $auditConfig->setCurrentUsername("beberlei");
        $auditConfig->setAuditedEntityClasses($this->auditedEntities);
        $auditConfig->setGlobalIgnoreColumns(array('ignoreme'));

        $this->auditManager = new AuditManager($auditConfig);
        $this->auditManager->registerEvents($evm);

        if (php_sapi_name() == 'cli' && isset($_SERVER['argv']) && (in_array('-v', $_SERVER['argv']) || in_array('--verbose', $_SERVER['argv']))) {
            $config->setSQLLogger(new \Doctrine\DBAL\Logging\EchoSQLLogger());
        }

        $this->em = \Doctrine\ORM\EntityManager::create($mainConnectionInstance, $config, $evm);

        $schemaTool = new \Doctrine\ORM\Tools\SchemaTool($this->em);
        $em = $this->em;

        $schemaTool->createSchema(array_map(function ($value) use ($em) {
            return $em->getClassMetadata($value);
        }, $this->schemaEntities));
    }

    public function testMultipleConnections()
    {
        $this->mainConnectionInstance->expects($this->never())
            ->method('executeQuery');

        //entity persister uses prepare and then execute on stmt, so only prepare is called
        $this->mainConnectionInstance->expects($this->exactly(1))
            ->method('prepare')
            ->with($this->stringContains('INSERT INTO MultipleConnectionEntity'));

        //ea uses executeUpdate when storing audit results
        $this->auditConnectionInstance->expects($this->once())
            ->method('executeUpdate')
            ->with($this->stringContains('INSERT INTO MultipleConnectionEntity_audit'));

        $entity = new MultipleConnectionEntity();

        $this->em->persist($entity);
        $this->em->flush();
    }
}

/**
 * @ORM\Entity
 */
class MultipleConnectionEntity
{
    /** @ORM\Id @ORM\GeneratedValue(strategy="AUTO") @ORM\Column(type="integer") */
    protected $id;

    public function getId()
    {
        return $this->id;
    }
}
