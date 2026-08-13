Feature: chat-4/threads
  Background:
    Given user "participant1" exists
    Given user "participant2" exists

  Scenario: Create a thread
    Given user "participant1" creates room "room" (v4)
      | roomType | 2 |
      | roomName | room |
    And user "participant1" adds user "participant2" to room "room" with 200 (v4)
    And user "participant1" sends thread "Thread 1" with message "Message 1" to room "room" with 201
    Then user "participant1" sees the following recent threads in room "room" with 200
      | t.id      | t.title  | t.numReplies | t.lastMessage | a.notificationLevel | firstMessage | lastMessage |
      | Message 1 | Thread 1 | 0            | 0             | 0                   | Message 1    | NULL        |

  Scenario: Create thread and reply
    Given user "participant1" creates room "room" (v4)
      | roomType | 2 |
      | roomName | room |
    And user "participant1" adds user "participant2" to room "room" with 200 (v4)
    And user "participant1" sends thread "Thread 1" with message "Message 1" to room "room" with 201
    And user "participant2" sends reply "Message 1-1" on message "Message 1" to room "room" with 201
    Then user "participant1" sees the following recent threads in room "room" with 200
      | t.id      | t.title  | t.numReplies | t.lastMessage | a.notificationLevel | firstMessage | lastMessage |
      | Message 1 | Thread 1 | 1            |  Message 1-1  | 0                   | Message 1    | Message 1-1 |
    Then user "participant1" sees the following messages in room "room" with 200
      | room | actorType | actorId      | actorDisplayName         | message     | messageParameters | parentMessage | threadTitle | threadReplies |
      | room | users     | participant2 | participant2-displayname | Message 1-1 | []                | Message 1     | Thread 1    | 1             |
      | room | users     | participant1 | participant1-displayname | Message 1   | []                |               | Thread 1    | 1             |
    Then user "participant1" sees the following system messages in room "room" with 200
      | room | actorType     | actorId      | systemMessage        | message                        | silent | messageParameters |
      | room | users         | participant1 | thread_created       | You created thread {title} | true   | {"actor":{"type":"user","id":"participant1","name":"participant1-displayname","mention-id":"participant1"},"title":{"type":"highlight","id":THREAD_ID(Thread 1),"name":"Thread 1"}} |
      | room | users         | participant1 | user_added           | You added {user}               | !ISSET | {"actor":{"type":"user","id":"participant1","name":"participant1-displayname","mention-id":"participant1"},"user":{"type":"user","id":"participant2","name":"participant2-displayname","mention-id":"participant2"}} |
      | room | users         | participant1 | conversation_created | You created the conversation   | !ISSET | {"actor":{"type":"user","id":"participant1","name":"participant1-displayname","mention-id":"participant1"}} |
    And user "participant1" renames thread "Thread 1" to "Thredited 1" in room "room" with 200
    Then user "participant1" sees the following recent threads in room "room" with 200
      | t.id      | t.title     | t.numReplies | t.lastMessage | a.notificationLevel | firstMessage | lastMessage |
      | Message 1 | Thredited 1 | 1            | Message 1-1   | 0                   | Message 1    | Message 1-1 |
    Then user "participant1" sees the following messages in room "room" with 200
      | room | actorType | actorId      | actorDisplayName         | message     | messageParameters | parentMessage |
      | room | users     | participant2 | participant2-displayname | Message 1-1 | []                | Message 1     |
      | room | users     | participant1 | participant1-displayname | Message 1   | []                |               |
    Then user "participant2" sees the following system messages in room "room" with 200
      | room | actorType     | actorId      | systemMessage        | message                        | silent | messageParameters |
      | room | users         | participant1 | thread_renamed       | {actor} renamed thread {title} | true   | {"actor":{"type":"user","id":"participant1","name":"participant1-displayname","mention-id":"participant1"},"title":{"type":"highlight","id":THREAD_ID(Thread 1),"name":"Thredited 1"}} |
      | room | users         | participant1 | thread_created       | {actor} created thread {title} | true   | {"actor":{"type":"user","id":"participant1","name":"participant1-displayname","mention-id":"participant1"},"title":{"type":"highlight","id":THREAD_ID(Thread 1),"name":"Thread 1"}} |
      | room | users         | participant1 | user_added           | {actor} added you              | !ISSET | {"actor":{"type":"user","id":"participant1","name":"participant1-displayname","mention-id":"participant1"},"user":{"type":"user","id":"participant2","name":"participant2-displayname","mention-id":"participant2"}} |
      | room | users         | participant1 | conversation_created | {actor} created the conversation | !ISSET | {"actor":{"type":"user","id":"participant1","name":"participant1-displayname","mention-id":"participant1"}} |

  Scenario: Non moderators can only rename their own threads
    Given user "participant1" creates room "room" (v4)
      | roomType | 2 |
      | roomName | room |
    And user "participant1" adds user "participant2" to room "room" with 200 (v4)
    And user "participant1" sends thread "Thread 1" with message "Message 1" to room "room" with 201
    And user "participant2" sends thread "Thread 2" with message "Message 2" to room "room" with 201
    Then user "participant1" sees the following recent threads in room "room" with 200
      | t.id      | t.title  | t.numReplies | t.lastMessage | a.notificationLevel | firstMessage | lastMessage |
      | Message 2 | Thread 2 | 0            | 0             | 0                   | Message 2    | NULL        |
      | Message 1 | Thread 1 | 0            | 0             | 0                   | Message 1    | NULL        |
    And user "participant2" renames thread "Thread 1" to "No permissions" in room "room" with 403
    And user "participant2" renames thread "Thread 2" to "My own thread" in room "room" with 200
    Then user "participant1" sees the following recent threads in room "room" with 200
      | t.id      | t.title       | t.numReplies | t.lastMessage | a.notificationLevel | firstMessage | lastMessage |
      | Message 2 | My own thread | 0            | 0             | 0                   | Message 2    | NULL        |
      | Message 1 | Thread 1      | 0            | 0             | 0                   | Message 1    | NULL        |
    And user "participant1" renames thread "Thread 1" to "Moderator thread" in room "room" with 200
    And user "participant1" renames thread "Thread 2" to "User thread" in room "room" with 200
    Then user "participant1" sees the following recent threads in room "room" with 200
      | t.id      | t.title          | t.numReplies | t.lastMessage | a.notificationLevel | firstMessage | lastMessage |
      | Message 2 | User thread      | 0            | 0             | 0                   | Message 2    | NULL        |
      | Message 1 | Moderator thread | 0            | 0             | 0                   | Message 1    | NULL        |

  Scenario: Notification levels
    Given user "participant1" creates room "room" (v4)
      | roomType | 2 |
      | roomName | room |
    And user "participant1" adds user "participant2" to room "room" with 200 (v4)
    And user "participant1" sends thread "Thread 1" with message "Message 1" to room "room" with 201
    And user "participant2" sends reply "Message 1-1" on thread "Thread 1" to room "room" with 201
    And user "participant1" has the following notifications
      | app | object_type | object_id | subject |
    And user "participant2" sends reply "Message 1-2" on message "Message 1" to room "room" with 201
    And user "participant1" has the following notifications
      | app    | object_type | object_id        | subject                                                               |
      | spreed | chat        | room/Message 1-2/Thread 1 | participant2-displayname replied to your message in conversation room (in thread Thread 1) |
    And user "participant1" subscribes to thread "Message 1" in room "room" with notification level 1 with 200
      | t.id      | t.title  | t.numReplies | t.lastMessage | a.notificationLevel | firstMessage | lastMessage |
      | Message 1 | Thread 1 | 2            | Message 1-2   | 1                   | Message 1    | Message 1-2 |
    And user "participant2" sends reply "Message 1-3" on thread "Thread 1" to room "room" with 201
    And user "participant1" has the following notifications
      | app    | object_type | object_id                 | subject                                                               |
      | spreed | chat        | room/Message 1-3/Thread 1 | participant2-displayname sent a message in conversation room (in thread Thread 1)          |
      | spreed | chat        | room/Message 1-2/Thread 1 | participant2-displayname replied to your message in conversation room (in thread Thread 1) |
    When user "participant2" sends reply "@participant1" on thread "Thread 1" to room "room" with 201
    Then user "participant1" has the following notifications
      | app    | object_type | object_id                   | subject                                                     |
      | spreed | chat        | room/@participant1/Thread 1 | participant2-displayname mentioned you in conversation room (in thread Thread 1) |
      | spreed | chat        | room/Message 1-3/Thread 1   | participant2-displayname sent a message in conversation room (in thread Thread 1)          |
      | spreed | chat        | room/Message 1-2/Thread 1   | participant2-displayname replied to your message in conversation room (in thread Thread 1) |

  Scenario: Thread titles are trimmed
    Given user "participant1" creates room "room" (v4)
      | roomType | 2 |
      | roomName | room |
    And user "participant1" adds user "participant2" to room "room" with 200 (v4)
    And user "participant1" sends thread "More than 200 chars 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789" with message "Message 1" to room "room" with 201
    Then user "participant1" sees the following recent threads in room "room" with 200
      | t.id      | t.title  | t.numReplies | t.lastMessage | a.notificationLevel | firstMessage | lastMessage |
      | Message 1 | More than 200 chars 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 … | 0            | 0          | 0                   | Message 1    | NULL        |
    Then user "participant1" sees the following system messages in room "room" with 200
      | room | actorType     | actorId      | systemMessage        | message                        | silent | messageParameters |
      | room | users         | participant1 | thread_created       | You created thread {title}     | true   | {"actor":{"type":"user","id":"participant1","name":"participant1-displayname","mention-id":"participant1"},"title":{"type":"highlight","id":THREAD_ID(More than 200 chars 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789),"name":"More than 200 chars 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 \u2026"}} |
      | room | users         | participant1 | user_added           | You added {user}               | !ISSET | {"actor":{"type":"user","id":"participant1","name":"participant1-displayname","mention-id":"participant1"},"user":{"type":"user","id":"participant2","name":"participant2-displayname","mention-id":"participant2"}} |
      | room | users         | participant1 | conversation_created | You created the conversation   | !ISSET | {"actor":{"type":"user","id":"participant1","name":"participant1-displayname","mention-id":"participant1"}} |
    And user "participant1" renames thread "More than 200 chars 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789" to "Still more than 200 chars 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789" in room "room" with 200
    Then user "participant1" sees the following recent threads in room "room" with 200
      | t.id      | t.title     | t.numReplies | t.lastMessage | a.notificationLevel | firstMessage | lastMessage |
      | Message 1 | Still more than 200 chars 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 1234… | 0            | 0             | 0                   | Message 1    | NULL        |
    Then user "participant2" sees the following system messages in room "room" with 200
      | room | actorType     | actorId      | systemMessage        | message                        | silent | messageParameters |
      | room | users         | participant1 | thread_renamed       | {actor} renamed thread {title} | true   | {"actor":{"type":"user","id":"participant1","name":"participant1-displayname","mention-id":"participant1"},"title":{"type":"highlight","id":THREAD_ID(More than 200 chars 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789),"name":"Still more than 200 chars 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 1234\u2026"}} |
      | room | users         | participant1 | thread_created       | {actor} created thread {title} | true   | {"actor":{"type":"user","id":"participant1","name":"participant1-displayname","mention-id":"participant1"},"title":{"type":"highlight","id":THREAD_ID(More than 200 chars 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789),"name":"More than 200 chars 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 123456789 \u2026"}} |
      | room | users         | participant1 | user_added           | {actor} added you              | !ISSET | {"actor":{"type":"user","id":"participant1","name":"participant1-displayname","mention-id":"participant1"},"user":{"type":"user","id":"participant2","name":"participant2-displayname","mention-id":"participant2"}} |
      | room | users         | participant1 | conversation_created | {actor} created the conversation | !ISSET | {"actor":{"type":"user","id":"participant1","name":"participant1-displayname","mention-id":"participant1"}} |

  Scenario: Recent threads are sorted by last activity
    Given user "participant1" creates room "room" (v4)
      | roomType | 2 |
      | roomName | room |
    And user "participant1" adds user "participant2" to room "room" with 200 (v4)
    And user "participant1" sends thread "Thread 1" with message "Message 1" to room "room" with 201
    And user "participant1" sends thread "Thread 2" with message "Message 2" to room "room" with 201
    Then user "participant1" sees the following recent threads in room "room" with 200
      | t.id      | t.title  | t.numReplies | t.lastMessage | a.notificationLevel | firstMessage | lastMessage |
      | Message 2 | Thread 2 | 0            | 0             | 0                   | Message 2    | NULL        |
      | Message 1 | Thread 1 | 0            | 0             | 0                   | Message 1    | NULL        |
    When user "participant1" sends reply "Message 1-1" on message "Message 1" to room "room" with 201
    Then user "participant1" sees the following recent threads in room "room" with 200
      | t.id      | t.title  | t.numReplies | t.lastMessage | a.notificationLevel | firstMessage | lastMessage |
      | Message 1 | Thread 1 | 1            | Message 1-1   | 0                   | Message 1    | Message 1-1 |
      | Message 2 | Thread 2 | 0            | 0             | 0                   | Message 2    | NULL        |

  Scenario: Change notification setting for thread
    Given user "participant1" creates room "room" (v4)
      | roomType | 2 |
      | roomName | room |
    And user "participant1" adds user "participant2" to room "room" with 200 (v4)
    And user "participant1" sends thread "Thread 1" with message "Message 1" to room "room" with 201
    Then user "participant1" sees the following recent threads in room "room" with 200
      | t.id      | t.title  | t.numReplies | t.lastMessage | a.notificationLevel | firstMessage | lastMessage |
      | Message 1 | Thread 1 | 0            | 0             | 0                   | Message 1    | NULL        |
    And user "participant1" subscribes to thread "Message 1" in room "room" with notification level 1 with 200
      | t.id      | t.title  | t.numReplies | t.lastMessage | a.notificationLevel | firstMessage | lastMessage |
      | Message 1 | Thread 1 | 0            | 0             | 1                   | Message 1    | NULL        |
    And user "participant1" subscribes to thread "Message 1" in room "room" with notification level 2 with 200
      | t.id      | t.title  | t.numReplies | t.lastMessage | a.notificationLevel | firstMessage | lastMessage |
      | Message 1 | Thread 1 | 0            | 0             | 2                   | Message 1    | NULL        |
    And user "participant1" subscribes to thread "Message 1" in room "room" with notification level 3 with 200
      | t.id      | t.title  | t.numReplies | t.lastMessage | a.notificationLevel | firstMessage | lastMessage |
      | Message 1 | Thread 1 | 0            | 0             | 3                   | Message 1    | NULL        |
    And user "participant1" subscribes to thread "Message 1" in room "room" with notification level 4 with 400
    And user "participant1" subscribes to thread "Message 1" in room "room" with notification level 0 with 200
      | t.id      | t.title  | t.numReplies | t.lastMessage | a.notificationLevel | firstMessage | lastMessage |
      | Message 1 | Thread 1 | 0            | 0             | 0                   | Message 1    | NULL        |

  Scenario: List of subscribed threads
    Given user "participant1" creates room "room1" (v4)
      | roomType | 2 |
      | roomName | room1 |
    And user "participant1" adds user "participant2" to room "room1" with 200 (v4)
    And user "participant1" sends thread "Thread 1" with message "Message 1" to room "room1" with 201
    Given user "participant2" creates room "room2" (v4)
      | roomType | 2 |
      | roomName | room2 |
    And user "participant2" adds user "participant1" to room "room2" with 200 (v4)
    And user "participant2" sends thread "Thread 2" with message "Message 2" to room "room2" with 201
    And user "participant2" sends thread "Thread 3" with message "Message 3" to room "room2" with 201
    Then user "participant1" sees the following subscribed threads
      | t.id      | t.token | t.title  | t.numReplies | t.lastMessage | a.notificationLevel | firstMessage | lastMessage |
      | Message 1 | room1   | Thread 1 | 0            | 0             | 0                   | Message 1    | NULL        |
    And user "participant1" subscribes to thread "Message 3" in room "room2" with notification level 1 with 200
    Then user "participant1" sees the following subscribed threads
      | t.id      | t.token | t.title  | t.numReplies | t.lastMessage | a.notificationLevel | firstMessage | lastMessage |
      | Message 3 | room2   | Thread 3 | 0            | 0             | 1                   | Message 3    | NULL        |
      | Message 1 | room1   | Thread 1 | 0            | 0             | 0                   | Message 1    | NULL        |
    When user "participant1" sends reply "Message 1-1" on message "Message 1" to room "room1" with 201
    Then user "participant1" sees the following subscribed threads
      | t.id      | t.token | t.title  | t.numReplies | t.lastMessage | a.notificationLevel | firstMessage | lastMessage |
      | Message 1 | room1   | Thread 1 | 1            | Message 1-1   | 0                   | Message 1    | Message 1-1 |
      | Message 3 | room2   | Thread 3 | 0            | 0             | 1                   | Message 3    | NULL        |
    When user "participant1" sends reply "Message 2-1" on message "Message 2" to room "room2" with 201
    Then user "participant1" sees the following subscribed threads
      | t.id      | t.token | t.title  | t.numReplies | t.lastMessage | a.notificationLevel | firstMessage | lastMessage |
      | Message 2 | room2   | Thread 2 | 1            | Message 2-1   | 0                   | Message 2    | Message 2-1 |
      | Message 1 | room1   | Thread 1 | 1            | Message 1-1   | 0                   | Message 1    | Message 1-1 |
      | Message 3 | room2   | Thread 3 | 0            | 0             | 1                   | Message 3    | NULL        |
    Then user "participant1" sees 1 number of subscribed threads with 0 offset
      | t.id      | t.token | t.title  | t.numReplies | t.lastMessage | a.notificationLevel | firstMessage | lastMessage |
      | Message 2 | room2   | Thread 2 | 1            | Message 2-1   | 0                   | Message 2    | Message 2-1 |
    Then user "participant1" sees 1 number of subscribed threads with 1 offset
      | t.id      | t.token | t.title  | t.numReplies | t.lastMessage | a.notificationLevel | firstMessage | lastMessage |
      | Message 1 | room1   | Thread 1 | 1            | Message 1-1   | 0                   | Message 1    | Message 1-1 |

  Scenario: Reply with an attachment
    Given user "participant1" creates room "room1" (v4)
      | roomType | 2 |
      | roomName | room1 |
    And user "participant1" adds user "participant2" to room "room1" with 200 (v4)
    And user "participant1" sends thread "Thread 1" with message "Message 1" to room "room1" with 201
    When user "participant2" shares "welcome.txt" with room "room1"
      | talkMetaData.caption      | Message 2 |
      | talkMetaData.replyTo      | Message 1 |
    Then user "participant1" sees the following messages in room "room1" with 200
      | room  | actorType | actorId      | actorDisplayName         | message   | messageParameters | parentMessage |
      | room1 | users     | participant2 | participant2-displayname | Message 2 | "IGNORE"          | Message 1     |
      | room1 | users     | participant1 | participant1-displayname | Message 1 | []                |               |
    Then user "participant1" sees the following recent threads in room "room1" with 200
      | t.id      | t.token | t.title  | t.numReplies | t.lastMessage | a.notificationLevel | firstMessage | lastMessage |
      | Message 1 | room1   | Thread 1 | 1            | Message 2     | 0                   | Message 1    | Message 2   |
    And user "participant1" has the following notifications
      | app    | object_type | object_id       | subject                                                                |
      | spreed | chat        | room1/Message 2/Thread 1 | participant2-displayname replied to your message in conversation room1 (in thread Thread 1) |
    Then user "participant2" sees the following subscribed threads
      | t.id      | t.token | t.title  | t.numReplies | t.lastMessage | a.notificationLevel | firstMessage | lastMessage |
      | Message 1 | room1   | Thread 1 | 1            | Message 2     | 0                   | Message 1    | Message 2   |

  # Story 4.1: a notification about activity inside a Thread names that Thread,
  # so the recipient can triage it without opening it. Activity outside any
  # Thread is unchanged and gains no thread text. The title is user content and
  # is never translated, hence the multibyte title here.
  Scenario: Thread notifications name the thread
    Given user "participant1" creates room "room1" (v4)
      | roomType | 2 |
      | roomName | room1 |
    And user "participant1" adds user "participant2" to room "room1" with 200 (v4)
    And user "participant1" sends thread "Bảo trì hệ thống" with message "Message 1" to room "room1" with 201
    And user "participant1" sends message "Message 2" to room "room1" with 201
    When user "participant2" sends reply "Message 3" on message "Message 1" to room "room1" with 201
    And user "participant2" sends reply "Message 4" on message "Message 2" to room "room1" with 201
    Then user "participant1" has the following notifications
      | app    | object_type | object_id                        | subject                                                                                            |
      | spreed | chat        | room1/Message 4                  | participant2-displayname replied to your message in conversation room1                              |
      | spreed | chat        | room1/Message 3/Bảo trì hệ thống | participant2-displayname replied to your message in conversation room1 (in thread Bảo trì hệ thống) |

  Scenario: Post a message with an attachment (not replying)
    Given user "participant1" creates room "room1" (v4)
      | roomType | 2 |
      | roomName | room1 |
    Given user "participant1" creates room "room2" (v4)
      | roomType | 2 |
      | roomName | room2 |
    And user "participant1" adds user "participant2" to room "room1" with 200 (v4)
    And user "participant1" sends thread "Thread 1" with message "Message 1" to room "room1" with 201
    And user "participant1" sends thread "Thread 2" with message "Message 2" to room "room2" with 201
    When user "participant2" shares "welcome.txt" with room "room1"
      | talkMetaData.caption      | Message 3 |
      | talkMetaData.threadId     | Message 1 |
    When user "participant2" shares "welcome.txt" with room "room1"
      | talkMetaData.caption      | Message 4 |
      | talkMetaData.threadId     | Message 2 |
    Then user "participant1" sees the following messages in room "room1" with 200
      | room  | actorType | actorId      | actorDisplayName         | message   | messageParameters | parentMessage |
      | room1 | users     | participant2 | participant2-displayname | Message 4 | "IGNORE"          |               |
      | room1 | users     | participant2 | participant2-displayname | Message 3 | "IGNORE"          | Message 1     |
      | room1 | users     | participant1 | participant1-displayname | Message 1 | []                |               |
    Then user "participant1" sees the following recent threads in room "room1" with 200
      | t.id      | t.token | t.title  | t.numReplies | t.lastMessage | a.notificationLevel | firstMessage | lastMessage |
      | Message 1 | room1   | Thread 1 | 1            | Message 3     | 0                   | Message 1    | Message 3   |
    Then user "participant1" sees the following recent threads in room "room2" with 200
      | t.id      | t.token | t.title  | t.numReplies | t.lastMessage | a.notificationLevel | firstMessage | lastMessage |
      | Message 2 | room2   | Thread 2 | 0            | 0             | 0                   | Message 2    | NULL        |
    And user "participant1" has the following notifications
      | app | object_type | object_id | subject |
    Then user "participant2" sees the following subscribed threads
      | t.id      | t.token | t.title  | t.numReplies | t.lastMessage | a.notificationLevel | firstMessage | lastMessage |
      | Message 1 | room1   | Thread 1 | 1            | Message 3     | 0                   | Message 1    | Message 3   |

  Scenario: Post a location to a thread
    Given user "participant1" creates room "room1" (v4)
      | roomType | 2 |
      | roomName | room1 |
    Given user "participant1" creates room "room2" (v4)
      | roomType | 2 |
      | roomName | room2 |
    And user "participant1" adds user "participant2" to room "room1" with 200 (v4)
    And user "participant1" sends thread "Thread 1" with message "Message 1" to room "room1" with 201
    And user "participant1" sends thread "Thread 2" with message "Message 2" to room "room2" with 201
    When user "participant2" shares rich-object "geo-location" "geo:52.5450511,13.3741463" '{"name":"Location name","latitude":"52.5450511","longitude":"13.3741463"}' to room "room1" in thread "Message 1" with 201 (v1)
    When user "participant2" shares rich-object "geo-location" "geo:52.5450511,13.3741463" '{"name":"Location name","latitude":"52.5450511","longitude":"13.3741463"}' to room "room1" in thread "Message 2" with 201 (v1)
    Then user "participant1" sees the following messages in room "room1" with 200
      | room  | actorType | actorId      | actorDisplayName         | message   | messageParameters | parentMessage |
      | room1 | users     | participant2 | participant2-displayname | {object}  | "IGNORE"          |               |
      | room1 | users     | participant2 | participant2-displayname | {object}  | "IGNORE"          | Message 1     |
      | room1 | users     | participant1 | participant1-displayname | Message 1 | []                |               |
    Then user "participant1" sees the following recent threads in room "room1" with 200
      | t.id      | t.token | t.title  | t.numReplies | t.lastMessage | a.notificationLevel | firstMessage | lastMessage |
      | Message 1 | room1   | Thread 1 | 1            | {object}      | 0                   | Message 1    | {object}    |
    Then user "participant1" sees the following recent threads in room "room2" with 200
      | t.id      | t.token | t.title  | t.numReplies | t.lastMessage | a.notificationLevel | firstMessage | lastMessage |
      | Message 2 | room2   | Thread 2 | 0            | 0             | 0                   | Message 2    | NULL        |
    And user "participant1" has the following notifications
      | app | object_type | object_id | subject |
    Then user "participant2" sees the following subscribed threads
      | t.id      | t.token | t.title  | t.numReplies | t.lastMessage | a.notificationLevel | firstMessage | lastMessage |
      | Message 1 | room1   | Thread 1 | 1            | {object}      | 0                   | Message 1    | {object}    |

  Scenario: Post a location to a thread
    Given user "participant1" creates room "room1" (v4)
      | roomType | 2 |
      | roomName | room1 |
    Given user "participant1" creates room "room2" (v4)
      | roomType | 2 |
      | roomName | room2 |
    And user "participant1" adds user "participant2" to room "room1" with 200 (v4)
    And user "participant1" sends thread "Thread 1" with message "Message 1" to room "room1" with 201
    And user "participant1" sends thread "Thread 2" with message "Message 2" to room "room2" with 201
    When user "participant2" creates a poll in room "room1" with 201
      | question   | What is the question?1 |
      | options    | ["Where are you?","How much is the fish?"] |
      | resultMode | public |
      | maxVotes   | unlimited |
      | threadId   | Thread 1 |
    When user "participant1" creates a poll in room "room1" with 201
      | question   | What is the question?2 |
      | options    | ["Where are you?","How much is the fish?"] |
      | resultMode | public |
      | maxVotes   | unlimited |
      | threadId   | Thread 2 |
    Then user "participant1" sees the following messages in room "room1" with 200
      | room  | actorType | actorId      | actorDisplayName         | message   | messageParameters | parentMessage |
      | room1 | users     | participant1 | participant1-displayname | {object}  | "IGNORE"          |               |
      | room1 | users     | participant2 | participant2-displayname | {object}  | "IGNORE"          | Message 1     |
      | room1 | users     | participant1 | participant1-displayname | Message 1 | []                |               |
    Then user "participant1" sees the following recent threads in room "room1" with 200
      | t.id      | t.token | t.title  | t.numReplies | t.lastMessage | a.notificationLevel | firstMessage | lastMessage |
      | Message 1 | room1   | Thread 1 | 1            | {object}      | 0                   | Message 1    | {object}    |
    Then user "participant1" sees the following recent threads in room "room2" with 200
      | t.id      | t.token | t.title  | t.numReplies | t.lastMessage | a.notificationLevel | firstMessage | lastMessage |
      | Message 2 | room2   | Thread 2 | 0            | 0             | 0                   | Message 2    | NULL        |
    And user "participant1" has the following notifications
      | app | object_type | object_id | subject |
    Then user "participant2" sees the following subscribed threads
      | t.id      | t.token | t.title  | t.numReplies | t.lastMessage | a.notificationLevel | firstMessage | lastMessage |
      | Message 1 | room1   | Thread 1 | 1            | {object}      | 0                   | Message 1    | {object}    |

  # Story 1.6: A Locked Thread refuses posted content on all nine paths.
  # One scenario per path (AC13) - typed message (path 1), bot API (path
  # 2), rich object share (path 4), poll (path 5), file share via room
  # share (path 6), attachment upload (path 7). Paths 3 (in-process bot,
  # the talk_webhook_demo app is not present in this repository), 8 and 9
  # (scheduling - no comment is written, covered in scheduled-messages.feature)
  # are documented separately (see Story 1.6 Dev Notes).

  Scenario: A Locked Thread refuses a typed reply (path 1)
    Given user "participant1" creates room "room" (v4)
      | roomType | 2 |
      | roomName | room |
    And user "participant1" adds user "participant2" to room "room" with 200 (v4)
    And user "participant1" sends thread "Thread 1" with message "Message 1" to room "room" with 201
    And user "participant1" sets thread "Thread 1" state to 2 in room "room" with 200
    When user "participant2" sends reply "Message 1-1" on thread "Thread 1" to room "room" with 400
    Then user "participant1" sees the following recent threads in room "room" with 200
      | t.id      | t.title  | t.numReplies | t.lastMessage | a.notificationLevel | firstMessage | lastMessage |
      | Message 1 | Thread 1 | 0            | 0             | 0                   | Message 1    | NULL        |
    Then user "participant1" sees the following messages in room "room" with 200
      | room | actorType | actorId      | actorDisplayName         | message   | messageParameters |
      | room | users     | participant1 | participant1-displayname | Message 1 | []                |

  Scenario: A Locked Thread refuses a bot API message (path 2)
    Given invoking occ with "talk:bot:install Bot Secret1234567890123456789012345678901234567890 https://localhost/bot1"
    And the command was successful
    And read bot ids from OCC
    And user "participant1" creates room "room" (v4)
      | roomType | 2 |
      | roomName | room |
    And invoking occ with "talk:bot:setup BOT(Bot) ROOM(room)"
    And the command was successful
    And user "participant1" sends thread "Thread 1" with message "Message 1" to room "room" with 201
    And user "participant1" sets thread "Thread 1" state to 2 in room "room" with 200
    When Bot "Bot" sends a message for room "room" with 400 (v1)
      | secret  | Secret1234567890123456789012345678901234567890 |
      | message | Response 1 |
      | replyTo | Message 1  |
    Then user "participant1" sees the following recent threads in room "room" with 200
      | t.id      | t.title  | t.numReplies | t.lastMessage | a.notificationLevel | firstMessage | lastMessage |
      | Message 1 | Thread 1 | 0            | 0             | 0                   | Message 1    | NULL        |

  Scenario: A Locked Thread refuses a shared rich object (path 4)
    Given user "participant1" creates room "room" (v4)
      | roomType | 2 |
      | roomName | room |
    And user "participant1" adds user "participant2" to room "room" with 200 (v4)
    And user "participant1" sends thread "Thread 1" with message "Message 1" to room "room" with 201
    And user "participant1" sets thread "Thread 1" state to 2 in room "room" with 200
    When user "participant2" shares rich-object "geo-location" "geo:52.5450511,13.3741463" '{"name":"Location name","latitude":"52.5450511","longitude":"13.3741463"}' to room "room" in thread "Message 1" with 400 (v1)
    Then user "participant1" sees the following recent threads in room "room" with 200
      | t.id      | t.title  | t.numReplies | t.lastMessage | a.notificationLevel | firstMessage | lastMessage |
      | Message 1 | Thread 1 | 0            | 0             | 0                   | Message 1    | NULL        |

  Scenario: A Locked Thread refuses a poll (path 5)
    Given user "participant1" creates room "room" (v4)
      | roomType | 2 |
      | roomName | room |
    And user "participant1" adds user "participant2" to room "room" with 200 (v4)
    And user "participant1" sends thread "Thread 1" with message "Message 1" to room "room" with 201
    And user "participant1" sets thread "Thread 1" state to 2 in room "room" with 200
    When user "participant2" creates a poll in room "room" with 400
      | question   | What is the question? |
      | options    | ["Where are you?","How much is the fish?"] |
      | resultMode | public |
      | maxVotes   | unlimited |
      | threadId   | Thread 1 |
    Then user "participant1" sees the following recent threads in room "room" with 200
      | t.id      | t.title  | t.numReplies | t.lastMessage | a.notificationLevel | firstMessage | lastMessage |
      | Message 1 | Thread 1 | 0            | 0             | 0                   | Message 1    | NULL        |

  Scenario: A Locked Thread refuses a file shared into it (path 6)
    Given user "participant1" creates room "room1" (v4)
      | roomType | 2 |
      | roomName | room1 |
    And user "participant1" adds user "participant2" to room "room1" with 200 (v4)
    And user "participant1" sends thread "Thread 1" with message "Message 1" to room "room1" with 201
    And user "participant1" sets thread "Thread 1" state to 2 in room "room1" with 200
    When user "participant2" shares "welcome.txt" with room "room1"
      | talkMetaData.caption      | Message 2 |
      | talkMetaData.threadId     | Message 1 |
    Then user "participant1" sees the following recent threads in room "room1" with 200
      | t.id      | t.title  | t.numReplies | t.lastMessage | a.notificationLevel | firstMessage | lastMessage |
      | Message 1 | Thread 1 | 0            | 0             | 0                   | Message 1    | NULL        |

  Scenario: A Locked Thread refuses an uploaded attachment (path 7)
    Given user "participant1" creates room "room1" (v4)
      | roomType | 2 |
      | roomName | room1 |
    And user "participant1" adds user "participant2" to room "room1" with 200 (v4)
    And user "participant1" sends thread "Thread 1" with message "Message 1" to room "room1" with 201
    And user "participant1" sets thread "Thread 1" state to 2 in room "room1" with 200
    And user "participant2" uploads file "attachment.txt" with content "Attachment content" to conversation folder for room "room1" with name "attachment.txt"
    When user "participant2" posts file "attachment.txt" from conversation folder of room "room1" with name "attachment.txt" with 400 (v1)
      | talkMetaData.threadId | Message 1 |
    Then user "participant1" sees the following recent threads in room "room1" with 200
      | t.id      | t.title  | t.numReplies | t.lastMessage | a.notificationLevel | firstMessage | lastMessage |
      | Message 1 | Thread 1 | 0            | 0             | 0                   | Message 1    | NULL        |

  # Story 1.7: A Locked Thread refuses edits, deletes, pins and reactions -
  # the third enforcement seam, extending the same ensureNotLocked()/
  # LockedException mechanism Story 1.6 built to
  # ChatManager::editMessage()/deleteMessage()/pinMessage()/unpinMessage()
  # and ReactionManager::addReactionMessage()/deleteReactionMessage().

  Scenario: A Locked Thread refuses editing a message
    Given user "participant1" creates room "room" (v4)
      | roomType | 2 |
      | roomName | room |
    And user "participant1" adds user "participant2" to room "room" with 200 (v4)
    And user "participant1" sends thread "Thread 1" with message "Message 1" to room "room" with 201
    And user "participant1" sets thread "Thread 1" state to 2 in room "room" with 200
    When user "participant1" edits message "Message 1" in room "room" to "Message 1 edited" with 400
    Then user "participant1" sees the following recent threads in room "room" with 200
      | t.id      | t.title  | t.numReplies | t.lastMessage | a.notificationLevel | firstMessage | lastMessage |
      | Message 1 | Thread 1 | 0            | 0             | 0                   | Message 1    | NULL        |

  Scenario: A Locked Thread refuses deleting a message
    Given user "participant1" creates room "room" (v4)
      | roomType | 2 |
      | roomName | room |
    And user "participant1" adds user "participant2" to room "room" with 200 (v4)
    And user "participant1" sends thread "Thread 1" with message "Message 1" to room "room" with 201
    And user "participant2" sends reply "Message 1-1" on message "Message 1" to room "room" with 201
    And user "participant1" sets thread "Thread 1" state to 2 in room "room" with 200
    When user "participant2" deletes message "Message 1-1" from room "room" with 400
    Then user "participant1" sees the following recent threads in room "room" with 200
      | t.id      | t.title  | t.numReplies | t.lastMessage | a.notificationLevel | firstMessage | lastMessage |
      | Message 1 | Thread 1 | 1            | Message 1-1   | 0                   | Message 1    | Message 1-1 |

  Scenario: A Locked Thread refuses pinning a message
    Given user "participant1" creates room "room" (v4)
      | roomType | 2 |
      | roomName | room |
    And user "participant1" adds user "participant2" to room "room" with 200 (v4)
    And user "participant1" sends thread "Thread 1" with message "Message 1" to room "room" with 201
    And user "participant1" sets thread "Thread 1" state to 2 in room "room" with 200
    When user "participant1" pins message "Message 1" in room "room" with 400
    Then user "participant1" is participant of the following rooms (v4)
      | id   | type | lastPinnedId | hiddenPinnedId |
      | room | 2    | EMPTY        | EMPTY          |

  Scenario: A Locked Thread refuses unpinning a message
    Given user "participant1" creates room "room" (v4)
      | roomType | 2 |
      | roomName | room |
    And user "participant1" adds user "participant2" to room "room" with 200 (v4)
    And user "participant1" sends thread "Thread 1" with message "Message 1" to room "room" with 201
    And user "participant1" pins message "Message 1" in room "room" with 200
    And user "participant1" sets thread "Thread 1" state to 2 in room "room" with 200
    When user "participant1" unpins message "Message 1" in room "room" with 400
    Then user "participant1" is participant of the following rooms (v4)
      | id   | type | lastPinnedId | hiddenPinnedId |
      | room | 2    | Message 1    | EMPTY          |

  Scenario: A Locked Thread refuses adding a reaction
    Given user "participant1" creates room "room" (v4)
      | roomType | 2 |
      | roomName | room |
    And user "participant1" adds user "participant2" to room "room" with 200 (v4)
    And user "participant1" sends thread "Thread 1" with message "Message 1" to room "room" with 201
    And user "participant1" sets thread "Thread 1" state to 2 in room "room" with 200
    When user "participant2" react with "🎉" on message "Message 1" to room "room" with 400
    Then user "participant1" sees the following recent threads in room "room" with 200
      | t.id      | t.title  | t.numReplies | t.lastMessage | a.notificationLevel | firstMessage | lastMessage |
      | Message 1 | Thread 1 | 0            | 0             | 0                   | Message 1    | NULL        |

  Scenario: A Locked Thread refuses removing a reaction
    Given user "participant1" creates room "room" (v4)
      | roomType | 2 |
      | roomName | room |
    And user "participant1" adds user "participant2" to room "room" with 200 (v4)
    And user "participant1" sends thread "Thread 1" with message "Message 1" to room "room" with 201
    And user "participant2" react with "🎉" on message "Message 1" to room "room" with 201
      | actorType | actorId      | actorDisplayName         | reaction |
      | users     | participant2 | participant2-displayname | 🎉       |
    And user "participant1" sets thread "Thread 1" state to 2 in room "room" with 200
    When user "participant2" delete react with "🎉" on message "Message 1" to room "room" with 400
    Then user "participant1" retrieve reactions "all" of message "Message 1" in room "room" with 200
      | actorType | actorId      | actorDisplayName         | reaction |
      | users     | participant2 | participant2-displayname | 🎉       |

  Scenario: Unlocking a Thread lets edits, deletes, pins and reactions succeed again (AC6)
    Given user "participant1" creates room "room" (v4)
      | roomType | 2 |
      | roomName | room |
    And user "participant1" adds user "participant2" to room "room" with 200 (v4)
    And user "participant1" sends thread "Thread 1" with message "Message 1" to room "room" with 201
    And user "participant2" sends reply "Message 1-1" on message "Message 1" to room "room" with 201
    And user "participant1" sets thread "Thread 1" state to 2 in room "room" with 200
    When user "participant1" edits message "Message 1" in room "room" to "Message 1 edited" with 400
    And user "participant2" deletes message "Message 1-1" from room "room" with 400
    And user "participant1" pins message "Message 1" in room "room" with 400
    And user "participant2" react with "🎉" on message "Message 1" to room "room" with 400
    And user "participant1" sets thread "Thread 1" state to 0 in room "room" with 200
    Then user "participant1" edits message "Message 1" in room "room" to "Message 1 edited" with 200
    And user "participant2" deletes message "Message 1-1" from room "room" with 200
    And user "participant1" pins message "Message 1 edited" in room "room" with 200
    And user "participant2" react with "🎉" on message "Message 1 edited" to room "room" with 201
      | actorType | actorId      | actorDisplayName         | reaction |
      | users     | participant2 | participant2-displayname | 🎉       |

  Scenario: Thread survives its root message being deleted (Story 1.10, AC1)
    Given user "participant1" creates room "room" (v4)
      | roomType | 2 |
      | roomName | room |
    And user "participant1" adds user "participant2" to room "room" with 200 (v4)
    And user "participant1" sends thread "Thread A" with message "Message A" to room "room" with 201
    And user "participant1" deletes message "Message A" from room "room" with 200
    Then user "participant1" sees the following recent threads in room "room" with 200
      | t.id      | t.title  | t.numReplies | t.lastMessage | a.notificationLevel | firstMessage | lastMessage |
      | Message A | Thread A | 0            | 0             | 0                   | Message A    | NULL        |

  Scenario: A Thread whose root has expired but not yet been reaped degrades gracefully (Story 1.10, AC5)
    Given user "participant1" creates room "room" (v4)
      | roomType | 3 |
      | roomName | room |
    And user "participant1" adds user "participant2" to room "room" with 200 (v4)
    And user "participant1" set the message expiration to 3 of room "room" with 200 (v4)
    And user "participant1" sends thread "Thread B" with message "Message B" to room "room" with 201
    And wait for 3 seconds
    And force run "OCA\Talk\BackgroundJob\ExpireChatMessages" background jobs
    Then user "participant1" sees the following recent threads in room "room" with 200
      | t.id      | t.title  | t.numReplies | t.lastMessage | a.notificationLevel | firstMessage | lastMessage |
      | Message B | Thread B | 0            | 0             | 0                   | NULL         | NULL        |

  Scenario: Thread orphaned by message expiry is reaped (Story 1.10, AC2, AC3, AC4)
    Given user "participant1" creates room "room" (v4)
      | roomType | 3 |
      | roomName | room |
    And user "participant1" adds user "participant2" to room "room" with 200 (v4)
    And user "participant1" set the message expiration to 3 of room "room" with 200 (v4)
    And user "participant1" sends thread "Thread C" with message "Message C" to room "room" with 201
    And wait for 3 seconds
    And force run "OCA\Talk\BackgroundJob\ExpireChatMessages" background jobs
    And force run "OCA\Talk\BackgroundJob\ReapOrphanedThreads" background jobs
    Then user "participant1" sees the following recent threads in room "room" with 200

  # Story 4.2: locking, closing or reopening a Thread notifies the participants
  # that follow it, so nobody has to learn a Thread is shut by writing a reply
  # and having it refused. The notification lives under the `room` object type
  # with the bare room token as object id (AD-12) - never `chat`, which
  # markMentionNotificationsRead() would erase, and never a new object type,
  # which the shipped Android and iOS clients would drop unhandled. The
  # invitation notification of the group conversation shares that object type and
  # object id, so the rows below are told apart by their subject.

  Scenario: Locking a Thread with a reason notifies its followers (Story 4.2, AC1)
    Given user "participant1" creates room "room" (v4)
      | roomType | 2 |
      | roomName | room |
    And user "participant1" adds user "participant2" to room "room" with 200 (v4)
    And user "participant1" sends thread "Thread 1" with message "Message 1" to room "room" with 201
    And user "participant2" subscribes to thread "Message 1" in room "room" with notification level 1 with 200
      | t.id      | t.title  | t.numReplies | t.lastMessage | a.notificationLevel | firstMessage | lastMessage |
      | Message 1 | Thread 1 | 0            | 0             | 1                   | Message 1    | NULL        |
    When user "participant1" sets thread "Thread 1" state to 2 in room "room" with 200
      | reason | Off topic |
    Then user "participant2" has the following notifications
      | app    | object_type | object_id | subject                                                                          |
      | spreed | room        | room      | participant1-displayname locked thread Thread 1 in conversation room (Off topic) |
      | spreed | room        | room      | participant1-displayname invited you to a group conversation: room               |
    # The Thread Manager is never notified of their own action
    And user "participant1" has the following notifications
      | app | object_type | object_id | subject |

  Scenario: Closing and reopening a Thread notifies its followers on the same terms (Story 4.2, AC1)
    Given user "participant1" creates room "room" (v4)
      | roomType | 2 |
      | roomName | room |
    And user "participant1" adds user "participant2" to room "room" with 200 (v4)
    And user "participant1" sends thread "Thread 1" with message "Message 1" to room "room" with 201
    And user "participant2" subscribes to thread "Message 1" in room "room" with notification level 1 with 200
    When user "participant1" sets thread "Thread 1" state to 1 in room "room" with 200
    Then user "participant2" has the following notifications
      | app    | object_type | object_id | subject                                                              |
      | spreed | room        | room      | participant1-displayname closed thread Thread 1 in conversation room |
      | spreed | room        | room      | participant1-displayname invited you to a group conversation: room   |
    # A requested state equal to the current state emits neither a system message
    # nor a notification (Story 4.2, AC8)
    When user "participant1" sets thread "Thread 1" state to 1 in room "room" with 200
    Then user "participant2" has the following notifications
      | app    | object_type | object_id | subject                                                              |
      | spreed | room        | room      | participant1-displayname closed thread Thread 1 in conversation room |
      | spreed | room        | room      | participant1-displayname invited you to a group conversation: room   |
    When user "participant1" sets thread "Thread 1" state to 0 in room "room" with 200
    Then user "participant2" has the following notifications
      | app    | object_type | object_id | subject                                                                |
      | spreed | room        | room      | participant1-displayname reopened thread Thread 1 in conversation room |
      | spreed | room        | room      | participant1-displayname closed thread Thread 1 in conversation room   |
      | spreed | room        | room      | participant1-displayname invited you to a group conversation: room     |

  Scenario: A muted follower and a non-follower are not notified (Story 4.2, AC3, AC4)
    Given user "participant3" exists
    And user "participant1" creates room "room" (v4)
      | roomType | 2 |
      | roomName | room |
    And user "participant1" adds user "participant2" to room "room" with 200 (v4)
    And user "participant1" adds user "participant3" to room "room" with 200 (v4)
    And user "participant1" sends thread "Thread 1" with message "Message 1" to room "room" with 201
    # participant2 muted the Thread, participant3 has no thread attendee row at all
    And user "participant2" subscribes to thread "Message 1" in room "room" with notification level 3 with 200
    When user "participant1" sets thread "Thread 1" state to 2 in room "room" with 200
    # Only the invitation of the group conversation, no lifecycle notification
    Then user "participant2" has the following notifications
      | app    | object_type | object_id | subject                                                            |
      | spreed | room        | room      | participant1-displayname invited you to a group conversation: room |
    And user "participant3" has the following notifications
      | app    | object_type | object_id | subject                                                            |
      | spreed | room        | room      | participant1-displayname invited you to a group conversation: room |

  Scenario: A Closed Thread revived by a reply produces only the ordinary reply notification (Story 4.2, AC6)
    Given user "participant1" creates room "room" (v4)
      | roomType | 2 |
      | roomName | room |
    And user "participant1" adds user "participant2" to room "room" with 200 (v4)
    And user "participant1" sends thread "Thread 1" with message "Message 1" to room "room" with 201
    And user "participant2" subscribes to thread "Message 1" in room "room" with notification level 1 with 200
    And user "participant1" sets thread "Thread 1" state to 1 in room "room" with 200
    And user "participant2" has the following notifications
      | app    | object_type | object_id | subject                                                              |
      | spreed | room        | room      | participant1-displayname closed thread Thread 1 in conversation room |
      | spreed | room        | room      | participant1-displayname invited you to a group conversation: room   |
    # ThreadService::reviveIfClosed() emits no system message, so it emits no
    # state-change notification either
    When user "participant2" sends reply "Message 1-1" on message "Message 1" to room "room" with 201
    Then user "participant2" has the following notifications
      | app    | object_type | object_id | subject                                                              |
      | spreed | room        | room      | participant1-displayname closed thread Thread 1 in conversation room |
      | spreed | room        | room      | participant1-displayname invited you to a group conversation: room   |
    And user "participant1" has the following notifications
      | app    | object_type | object_id                 | subject                                                                                    |
      | spreed | chat        | room/Message 1-1/Thread 1 | participant2-displayname replied to your message in conversation room (in thread Thread 1) |

  # This is the scenario the object type decision rests on: `chat` was rejected
  # because Chat\Notifier::markMentionNotificationsRead() marks every `chat`
  # notification of that user in the room as processed, so merely catching up on
  # the conversation would silently dismiss the lifecycle notification. Setting
  # the read marker to the last message of the room is exactly what triggers that
  # path - the mention below is there to show it fired.
  Scenario: Reading the conversation does not dismiss a Thread lifecycle notification (Story 4.2, AC1)
    Given user "participant1" creates room "room" (v4)
      | roomType | 2 |
      | roomName | room |
    And user "participant1" adds user "participant2" to room "room" with 200 (v4)
    And user "participant1" sends thread "Thread 1" with message "Message 1" to room "room" with 201
    And user "participant2" subscribes to thread "Message 1" in room "room" with notification level 1 with 200
    And user "participant1" sets thread "Thread 1" state to 2 in room "room" with 200
      | reason | Off topic |
    And user "participant1" sends message "Message 2 @participant2" to room "room" with 201
    And user "participant2" has the following notifications
      | app    | object_type | object_id                    | subject                                                                          |
      | spreed | chat        | room/Message 2 @participant2 | participant1-displayname mentioned you in conversation room                      |
      | spreed | room        | room                         | participant1-displayname locked thread Thread 1 in conversation room (Off topic) |
      | spreed | room        | room                         | participant1-displayname invited you to a group conversation: room               |
    When user "participant2" reads message "NULL" in room "room" with 200
    Then user "participant2" has the following notifications
      | app    | object_type | object_id | subject                                                                          |
      | spreed | room        | room      | participant1-displayname locked thread Thread 1 in conversation room (Off topic) |
      | spreed | room        | room      | participant1-displayname invited you to a group conversation: room               |
