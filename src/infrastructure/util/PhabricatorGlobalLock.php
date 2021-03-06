<?php

/*
 * Copyright 2012 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/**
 * Global, MySQL-backed lock. This is a high-reliability, low-performance
 * global lock.
 *
 * The lock is maintained by using GET_LOCK() in MySQL, and automatically
 * released when the connection terminates. Thus, this lock can safely be used
 * to control access to shared resources without implementing any sort of
 * timeout or override logic: the lock can't normally be stuck in a locked state
 * with no process actually holding the lock.
 *
 * However, acquiring the lock is moderately expensive (several network
 * roundtrips). This makes it unsuitable for tasks where lock performance is
 * important.
 *
 *    $lock = PhabricatorGlobalLock::newLock('example');
 *    $lock->lock();
 *      do_contentious_things();
 *    $lock->unlock();
 *
 * NOTE: This lock is not completely global; it is namespaced to the active
 * storage namespace so that unit tests running in separate table namespaces
 * are isolated from one another.
 *
 * @task construct  Constructing Locks
 * @task impl       Implementation
 */
final class PhabricatorGlobalLock extends PhutilLock {

  private $lockname;
  private $conn;


/* -(  Constructing Locks  )------------------------------------------------- */


  public static function newLock($name) {
    $namespace = PhabricatorLiskDAO::getStorageNamespace();
    $full_name = 'global:'.$namespace.':'.$name;

    $lock = self::getLock($full_name);
    if (!$lock) {
      $lock = new PhabricatorGlobalLock($full_name);
      $lock->lockname = $name;
      self::registerLock($lock);
    }

    return $lock;
  }


/* -(  Implementation  )----------------------------------------------------- */

  protected function doLock($wait) {
    $conn = $this->conn;
    if (!$conn) {
      // NOTE: Using the 'repository' database somewhat arbitrarily, mostly
      // because the first client of locks is the repository daemons. We must
      // always use the same database for all locks, but don't access any
      // tables so we could use any valid database. We could build a
      // database-free connection instead, but that's kind of messy and we
      // might forget about it in the future if we vertically partition the
      // application.
      $dao = new PhabricatorRepository();

      // NOTE: Using "force_new" to make sure each lock is on its own
      // connection.
      $conn = $dao->establishConnection('w', $force_new = true);

      // NOTE: Since MySQL will disconnect us if we're idle for too long, we set
      // the wait_timeout to an enormous value, to allow us to hold the
      // connection open indefinitely (or, at least, for a year).
      queryfx($conn, 'SET wait_timeout = %d', 365 * 24 * 60 * 60);
    }

    $result = queryfx_one(
      $conn,
      'SELECT GET_LOCK(%s, %f)',
      'phabricator:'.$this->lockname,
      $wait);

    $ok = head($result);
    if (!$ok) {
      throw new PhutilLockException($this->getName());
    }

    $this->conn = $conn;
  }

  protected function doUnlock() {
    queryfx(
      $this->conn,
      'SELECT RELEASE_LOCK(%s)',
      'phabricator:'.$this->lockname);

    // TODO: There's no explicit close() method on connections right now. Once
    // we have one, we could close the connection here. Since we don't have
    // such a method, we need to keep the connection around in case lock() is
    // called again, so that long-running daemons don't gradually open
    // an unbounded number of connections.
  }

}
