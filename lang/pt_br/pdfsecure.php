<?php
$string['pluginname'] = 'PDF Secure';
$string['modulename'] = 'PDF Secure';
$string['modulename_help'] = 'O módulo PDF Secure entrega PDFs do curso por uma rota autenticada: o arquivo enviado não tem URL '
    . 'própria e o visualizador abre sem os botões de download e impressão. Por padrão ele também carimba cada página com o '
    . 'nome de quem abriu o documento. Um documento que pode ser lido sempre pode ser copiado — o que o carimbo garante é que '
    . 'qualquer cópia em circulação identifica a conta de onde saiu. Sites cujas estações já aplicam a própria '
    . 'marca d\'água podem desligar o carimbo nas configurações do plugin e manter todo o resto.';
$string['modulenameplural'] = 'PDFs Secures';
$string['pluginadministration'] = 'Administração do PDF Secure';
$string['pdfsecurename'] = 'Nome do PDF';
$string['pdfsecurename_help'] = 'Este é o nome do link que os estudantes verão na página do curso.';
$string['selectfile'] = 'Selecione os arquivos PDF';
$string['settingonlysecure'] = 'PDFs somente como PDF Secure';
$string['settingonlysecure_desc'] = 'Quando ativada, bloqueia PDFs nos formulários das atividades Arquivo e Pasta, oferece PDF Secure para PDFs arrastados '
    . 'ao curso e converte PDFs criados por outros caminhos, incluindo atividades PDFProtect quando o módulo está instalado. As atividades existentes são mantidas, mas ficam ocultas depois que os PDFs são confirmados '
    . 'no PDF Secure. Isto se aplica às atividades Arquivo, Pasta e PDFProtect; não altera PDFs em publicações de fórum, envios de tarefas, vídeos interativos ou certificados.';
$string['onlysecurevalidation'] = 'O PDF Secure aceita vários PDFs em uma atividade. Adicione uma atividade ou recurso > PDF Secure e coloque todos os PDFs nela. Remova o PDF desta atividade Arquivo ou Pasta.';
$string['dnduploadpdfsecure'] = 'Criar uma atividade PDF Secure (PDF protegido)';
$string['dnduploadzipsecure'] = 'Criar uma atividade PDF Secure com os PDFs deste ZIP';
$string['taskconvertplainpdfs'] = 'Converter PDF de uma atividade para PDF Secure';
$string['tasksweepplainpdfs'] = 'Procurar PDFs nas atividades compatíveis';

// Se deve carimbar.
$string['settingstampmode'] = 'Aplicar marca d\'água nos documentos';
$string['settingstampmode_desc'] = 'Se este site grava uma marca d\'água por leitor dentro de cada PDF que entrega. Desligue '
    . 'apenas onde as estações de trabalho já aplicam a própria marca — uma segunda marca sobre a primeira não acrescenta '
    . 'rastreabilidade e deixa o documento mais difícil de ler. Desligar não muda mais nada: o arquivo enviado continua sem URL '
    . 'própria, o leitor continua precisando estar autenticado e matriculado, o visualizador continua escondendo download e '
    . 'impressão, e o texto do documento continua indexado na Pesquisa Global. Também tira a etapa de reescrita do PDF, então '
    . 'documentos cifrados '
    . 'e arquivos com links, marcadores ou campos de formulário são entregues intactos em vez de recusados ou achatados.';
$string['stampmodefull'] = 'Sim — carimbar cada página com a identidade de quem lê';
$string['stampmodeoff'] = 'Não — entregar o documento sem marca (para sites com marca d\'água na estação)';

$string['completionreadall'] = 'Ler até o fim';
$string['completionreadall_help'] = 'Um PDF conta como lido quando o estudante visualiza pelo menos 90% das páginas, arredondadas para cima, e a última página. '
    . 'Escolha se isso se aplica a todos os PDFs ou a qualquer um deles. Ir direto ao fim não conclui um PDF.';
$string['completiondisabled'] = 'Não acompanhar a leitura';
$string['completionallfiles'] = 'Ler todas as páginas de todos os PDFs';
$string['completionanyfile'] = 'Ler todas as páginas de qualquer PDF';
$string['completiondetail:allfiles'] = 'Ler pelo menos 90% das páginas e a última página de todos os PDFs.';
$string['completiondetail:anyfile'] = 'Ler pelo menos 90% das páginas e a última página de qualquer PDF.';
$string['selectdocument'] = 'Escolha um PDF para ler.';
$string['pagesread'] = '{$a->read} de {$a->total} páginas lidas';
$string['readstatecomplete'] = 'Lido';
$string['readstateincomplete'] = 'Ainda não lido';
$string['eventcoursemoduleviewed'] = 'Atividade PDF Secure visualizada';

$string['privacy:exportpath'] = 'Progresso de leitura do PDF';
$string['privacy:watermarkedpath'] = 'Derivados de PDF com marca d\'água';
$string['privacy:metadata:pdfsecure_progress'] = 'O PDF Secure guarda o progresso de leitura de cada usuário em cada atividade '
    . 'PDF.';
$string['privacy:metadata:pdfsecure_progress:pdfsecureid'] = 'A atividade cujo PDF foi lido.';
$string['privacy:metadata:pdfsecure_progress:userid'] = 'O usuário que visualizou as páginas.';
$string['privacy:metadata:pdfsecure_progress:filekey'] = 'O nome do PDF cujas páginas foram visualizadas.';
$string['privacy:metadata:pdfsecure_progress:pages'] = 'A quantidade total de páginas informada pelo visualizador.';
$string['privacy:metadata:pdfsecure_progress:seen'] = 'Os números distintos das páginas informadas como visualizadas.';
$string['privacy:metadata:pdfsecure_progress:timemodified'] = 'O horário da última atualização do progresso.';
$string['privacy:metadata:pdfsecure_progress:timecompleted'] = 'O horário em que a regra de leitura até o fim foi cumprida pela '
    . 'primeira vez.';
$string['privacy:metadata:watermarked'] = 'O PDF Secure pode guardar derivados de PDF com marca d\'água por usuário no '
    . 'armazenamento de arquivos do Moodle.';
$string['resetprogress'] = 'Apagar o progresso de leitura';
$string['pdfsecure:addinstance'] = 'Adicionar uma atividade PDF Secure';
$string['pdfsecure:view'] = 'Ver atividades PDF Secure';
$string['errorcannotstamp'] = "Este PDF não pode ser usado: o site não conseguiu lê-lo para aplicar a marca do leitor. Ele pode estar protegido por senha ou danificado. Tire a proteção ou exporte de novo e envie outra vez.";
$string['errortoobigtostamp'] = "Este PDF é maior do que o tamanho que o site consegue marcar para os leitores. Envie um arquivo menor ou peça a um administrador para aumentar o limite.";
$string['cachedef_pagecount'] = 'Cache da contagem de páginas dos arquivos do PDF Secure';
$string['errorpagesmismatch'] = 'O total de páginas não corresponde a este PDF. Recarregue a página.';
$string['errorpagesnodocument'] = 'Esta atividade não tem documento para ler.';
$string['errorfileinvalid'] = 'Este PDF não pertence a esta atividade.';
$string['errorfiletype'] = 'Esta atividade aceita somente arquivos PDF.';
$string['errorzipunsupported'] = 'O envio de ZIP está indisponível porque o servidor não oferece suporte a arquivos ZIP.';
$string['errorzipinvalid'] = 'Não foi possível ler este ZIP com segurança.';
$string['errorzipentry'] = 'O ZIP deve conter somente arquivos PDF na raiz; pastas e outros tipos de arquivo não são aceitos.';
$string['errorzipencrypted'] = 'Arquivos criptografados dentro do ZIP não são aceitos.';
$string['errorzipsize'] = 'Este ZIP excede o limite de 50 itens ou 500 MB descompactados.';
$string['errorzipnovalidpdfs'] = 'O ZIP não contém nenhum PDF que possa ser adicionado ao PDF Secure.';
