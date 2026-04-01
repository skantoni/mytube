const { io } = require('socket.io-client');

const SERVER_URL = process.env.CHAT_SERVER_URL || 'http://127.0.0.1:3001';
const USER1 = { id: 99001, username: 'presence_test_u1' };
const USER2 = { id: 99002, username: 'presence_test_u2' };
const USER3 = { id: 99003, username: 'presence_test_u3' };

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

function withTimeout(promise, timeoutMs, label) {
    return Promise.race([
        promise,
        new Promise((_, reject) => {
            setTimeout(() => reject(new Error(`Timeout: ${label}`)), timeoutMs);
        })
    ]);
}

async function connectAndAuthenticate(user) {
    return new Promise((resolve, reject) => {
        const socket = io(SERVER_URL, {
            transports: ['websocket', 'polling'],
            reconnection: false,
            timeout: 5000
        });

        const onConnect = () => {
            socket.off('connect_error', onConnectError);
            socket.emit('authenticate', {
                userId: user.id,
                username: user.username
            });
            resolve(socket);
        };

        const onConnectError = (error) => {
            socket.off('connect', onConnect);
            reject(new Error(`Falha ao conectar user ${user.id}: ${error.message}`));
        };

        socket.once('connect', onConnect);
        socket.once('connect_error', onConnectError);
    });
}

function assertCondition(condition, message, failures) {
    if (!condition) {
        failures.push(message);
    }
}

(async () => {
    const sockets = [];
    const failures = [];
    const steps = [];

    try {
        console.log(`SERVER=${SERVER_URL}`);

        const user1Socket = await connectAndAuthenticate(USER1);
        sockets.push(user1Socket);

        const user2Socket = await connectAndAuthenticate(USER2);
        sockets.push(user2Socket);

        const user3Socket = await connectAndAuthenticate(USER3);
        sockets.push(user3Socket);

        await wait(600);
        steps.push('Conexões iniciais estabelecidas para os 3 usuários');

        const updates = [];
        user1Socket.on('contact_presence_update', (data) => {
            updates.push({
                userId: Number(data.userId),
                isOnline: Boolean(data.isOnline),
                ts: Date.now()
            });
            console.log(`UPDATE userId=${data.userId} isOnline=${Boolean(data.isOnline)}`);
        });

        const snapshotPromise = new Promise((resolve) => {
            user1Socket.once('contact_presence_snapshot', resolve);
        });

        user1Socket.emit('subscribe_contact_presence', {
            userId: USER1.id,
            contactIds: [USER2.id, USER3.id]
        });

        const snapshot = await withTimeout(snapshotPromise, 5000, 'snapshot inicial');
        console.log(`SNAPSHOT ${JSON.stringify(snapshot)}`);

        assertCondition(snapshot && snapshot.statuses, 'Snapshot inicial ausente ou inválido', failures);
        assertCondition(
            snapshot?.statuses?.[String(USER2.id)] === true,
            `Snapshot: esperado user ${USER2.id} online`,
            failures
        );
        assertCondition(
            snapshot?.statuses?.[String(USER3.id)] === true,
            `Snapshot: esperado user ${USER3.id} online`,
            failures
        );
        steps.push('Snapshot inicial recebido com ambos contatos online');

        const beforeUser2Offline = updates.length;
        user2Socket.disconnect();
        await wait(1300);
        const user2OfflineReceived = updates
            .slice(beforeUser2Offline)
            .some((eventData) => eventData.userId === USER2.id && eventData.isOnline === false);

        assertCondition(
            user2OfflineReceived,
            `Não recebeu update offline para user ${USER2.id}`,
            failures
        );
        steps.push('Desconexão de user2 gerou update offline');

        const beforeUser2Reconnect = updates.length;
        const user2ReconnectedSocket = await connectAndAuthenticate({
            id: USER2.id,
            username: `${USER2.username}_re`
        });
        sockets.push(user2ReconnectedSocket);
        await wait(1300);

        const user2OnlineAgain = updates
            .slice(beforeUser2Reconnect)
            .some((eventData) => eventData.userId === USER2.id && eventData.isOnline === true);

        assertCondition(
            user2OnlineAgain,
            `Não recebeu update online para user ${USER2.id} após reconexão`,
            failures
        );
        steps.push('Reconexão de user2 gerou update online');

        const beforeUser3Offline = updates.length;
        user3Socket.disconnect();
        await wait(1300);

        const user3OfflineReceived = updates
            .slice(beforeUser3Offline)
            .some((eventData) => eventData.userId === USER3.id && eventData.isOnline === false);

        assertCondition(
            user3OfflineReceived,
            `Não recebeu offline inicial para user ${USER3.id}`,
            failures
        );
        steps.push('User3 desligado para iniciar cenário multi-aba');

        const multiTabBaseline = updates.length;
        const user3TabASocket = await connectAndAuthenticate({
            id: USER3.id,
            username: `${USER3.username}_tabA`
        });
        sockets.push(user3TabASocket);

        await wait(350);

        const user3TabBSocket = await connectAndAuthenticate({
            id: USER3.id,
            username: `${USER3.username}_tabB`
        });
        sockets.push(user3TabBSocket);

        await wait(1300);

        const multiTabOnlineEvents = updates
            .slice(multiTabBaseline)
            .filter((eventData) => eventData.userId === USER3.id && eventData.isOnline === true);

        assertCondition(
            multiTabOnlineEvents.length >= 1,
            `Esperado update online para user ${USER3.id} no cenário multi-aba`,
            failures
        );

        const beforeFirstTabClose = updates.length;
        user3TabASocket.disconnect();
        await wait(1300);

        const offlineAfterFirstTabClose = updates
            .slice(beforeFirstTabClose)
            .filter((eventData) => eventData.userId === USER3.id && eventData.isOnline === false);

        assertCondition(
            offlineAfterFirstTabClose.length === 0,
            `Não deveria emitir offline para user ${USER3.id} ao fechar só 1 aba`,
            failures
        );

        const beforeSecondTabClose = updates.length;
        user3TabBSocket.disconnect();
        await wait(1300);

        const offlineAfterSecondTabClose = updates
            .slice(beforeSecondTabClose)
            .filter((eventData) => eventData.userId === USER3.id && eventData.isOnline === false);

        assertCondition(
            offlineAfterSecondTabClose.length >= 1,
            `Esperado offline para user ${USER3.id} ao fechar a última aba`,
            failures
        );
        steps.push('Cenário multi-aba validado para user3');

        console.log('\n=== RESUMO PASSOS ===');
        steps.forEach((step, index) => {
            console.log(`${index + 1}. ${step}`);
        });

        if (failures.length > 0) {
            console.log('\n=== FALHAS ===');
            failures.forEach((failure, index) => {
                console.log(`${index + 1}. ${failure}`);
            });
            process.exitCode = 1;
        } else {
            console.log('\nRESULTADO: PASSOU');
            process.exitCode = 0;
        }
    } catch (error) {
        console.error(`ERRO: ${error.message}`);
        process.exitCode = 1;
    } finally {
        await wait(250);
        sockets.forEach((socket) => {
            if (socket && socket.connected) {
                socket.disconnect();
            }
        });
        setTimeout(() => {
            process.exit(process.exitCode || 0);
        }, 200);
    }
})();
